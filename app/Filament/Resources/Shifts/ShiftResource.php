<?php

namespace App\Filament\Resources\Shifts;

use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Filament\Resources\Shifts\Pages\CreateShift;
use App\Filament\Resources\Shifts\Pages\EditShift;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static ?string $modelLabel = 'turno';

    protected static ?string $pluralModelLabel = 'turnos';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forUser(Auth::user())->with(['jobSite', 'worker.roles', 'requiredRole']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    public static function fields(bool $multiple = false): array
    {
        $worker = Select::make($multiple ? 'workers' : 'user_id')->label($multiple ? 'Trabajadores' : 'Trabajador')
            ->multiple($multiple)->required()->searchable()
            ->options(function (Get $get): array {
                $role = ShiftRole::tryFrom((string) $get('role_required'));

                return $role && $get('job_site_id') && array_key_exists($get('job_site_id'), self::sites())
                    ? app(ShiftService::class)->eligibleWorkers((int) $get('job_site_id'), $role)->orderBy('name')->pluck('name', 'id')->all()
                    : [];
            });

        return [
            Select::make('job_site_id')->label('Obra')->options(fn () => self::sites())->required()->searchable()->live()
                ->afterStateUpdated(function (Set $set) use ($multiple): void {
                    $set('job_scope_id', null);
                    $set($multiple ? 'workers' : 'user_id', $multiple ? [] : null);
                }),
            Select::make('job_scope_id')->label('Alcance')->searchable()
                ->options(fn (Get $get) => JobScope::where('job_site_id', $get('job_site_id'))->orderBy('name')->pluck('name', 'id')),
            Select::make('role_required')->label('Rol requerido')->options(self::roles())->required()->live()
                ->afterStateUpdated(fn (Set $set) => $set($multiple ? 'workers' : 'user_id', $multiple ? [] : null)),
            $worker,
            DateTimePicker::make('starts_at')->label('Inicio')->timezone(config('app.business_timezone'))->seconds(false)->required(),
            DateTimePicker::make('ends_at')->label('Fin')->timezone(config('app.business_timezone'))->seconds(false)->required()->after('starts_at'),
            Select::make('status')->label('Estado')->options(self::statuses(false))->default(ShiftStatus::Programmed->value)->required(),
            Textarea::make('notes')->label('Notas')->maxLength(5000)->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('starts_at')->label('Inicio')->dateTime('M j, Y H:i', config('app.business_timezone'))->sortable(),
            TextColumn::make('ends_at')->label('Fin')->dateTime('M j, Y H:i', config('app.business_timezone')),
            TextColumn::make('jobSite.name')->label('Obra')->searchable(),
            TextColumn::make('worker.name')->label('Trabajador')->searchable()
                ->icon('heroicon-o-user-circle')
                ->description(fn (Shift $record): string => $record->worker?->roles->pluck('name')->join(', ') ?? ''),
            TextColumn::make('role_required')->label('Rol requerido')->state(fn (Shift $record) => $record->role_required?->label() ?? $record->requiredRole?->name),
            TextColumn::make('status')->label('Estado')->badge()
                ->formatStateUsing(fn (ShiftStatus $state) => $state->label())
                ->color(fn (ShiftStatus $state) => $state->color()),
            TextColumn::make('duration')->label('Duración')
                ->state(fn (Shift $record): string => round($record->starts_at->diffInMinutes($record->ends_at)).' min'),
        ])->filters([
            SelectFilter::make('job_site_id')->label('Obra')->options(fn () => self::sites()),
            SelectFilter::make('role_required')->label('Rol requerido')->options(self::roles())
                ->query(function (Builder $query, array $data): Builder {
                    $role = ShiftRole::tryFrom((string) ($data['value'] ?? ''));

                    return $query->when($role, fn (Builder $shifts) => $shifts->withRequiredRole($role));
                }),
            SelectFilter::make('status')->label('Estado')->options(self::statuses()),
            SelectFilter::make('user_id')->label('Trabajador')->searchable()
                ->options(fn () => User::workersVisibleTo(Auth::user())->orderBy('name')->pluck('name', 'id')),
            Filter::make('period')->label('Fechas')
                ->schema([DatePicker::make('from')->label('Desde'), DatePicker::make('to')->label('Hasta')->afterOrEqual('from')])
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn (Builder $shifts, string $date) => $shifts->where('ends_at', '>', self::localDay($date)->utc()))
                    ->when($data['to'] ?? null, fn (Builder $shifts, string $date) => $shifts->where('starts_at', '<', self::localDay($date)->addDay()->utc()))),
        ])->recordActions([
            EditAction::make(),
            Action::make('duplicate')->label('Duplicar')->authorize('update')
                ->schema(fn () => self::fields())
                ->fillForm(fn (Shift $record): array => self::formData($record) + [
                    'starts_at' => $record->starts_at->setTimezone(config('app.business_timezone'))->addDay()->utc()->format('Y-m-d H:i:s'),
                    'ends_at' => $record->ends_at->setTimezone(config('app.business_timezone'))->addDay()->utc()->format('Y-m-d H:i:s'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(ShiftService::class)->save(Auth::user(), null, $data);
                        Notification::make()->title('Turno duplicado')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title('No se pudo duplicar el turno')
                            ->body($exception->validator->errors()->first())->danger()->send();
                    }
                }),
            Action::make('cancel')->label('Cancelar')->color('danger')->authorize('cancel')
                ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength(1000)])
                ->action(fn (Shift $record, array $data) => app(ShiftService::class)->cancel(Auth::user(), $record, $data['reason']))
                ->successNotificationTitle('Turno cancelado'),
        ])->defaultSort('starts_at', 'desc');
    }

    public static function formData(Shift $record): array
    {
        return [
            'job_site_id' => $record->job_site_id,
            'job_scope_id' => $record->job_scope_id,
            'role_required' => $record->effectiveRole()?->value,
            'user_id' => $record->user_id,
            'status' => $record->status->value,
            'notes' => $record->notes,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShifts::route('/'),
            'create' => CreateShift::route('/create'),
            'edit' => EditShift::route('/{record}/edit'),
        ];
    }

    private static function roles(): array
    {
        return array_column(array_map(fn (ShiftRole $role) => [$role->value, $role->label()], ShiftRole::cases()), 1, 0);
    }

    private static function statuses(bool $includeCancelled = true): array
    {
        $statuses = [];
        foreach (ShiftStatus::cases() as $status) {
            if ($includeCancelled || $status !== ShiftStatus::Cancelled) {
                $statuses[$status->value] = $status->label();
            }
        }

        return $statuses;
    }

    private static function sites(): array
    {
        return JobSite::visibleTo(Auth::user())->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function localDay(string $date): Carbon
    {
        return Carbon::parse($date, config('app.business_timezone'))->startOfDay();
    }
}
