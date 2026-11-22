<?php

namespace App\Filament\Resources\ChangeOrders;

use App\Enums\ChangeOrderStatus;
use App\Exceptions\InvalidChangeOrderTransitionException;
use App\Filament\Resources\ChangeOrders\Pages\CreateChangeOrder;
use App\Filament\Resources\ChangeOrders\Pages\EditChangeOrder;
use App\Filament\Resources\ChangeOrders\Pages\ListChangeOrders;
use App\Filament\Resources\ChangeOrders\Pages\ViewChangeOrder;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Services\ChangeOrderService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ChangeOrderResource extends Resource
{
    protected static ?string $model = ChangeOrder::class;

    protected static ?string $modelLabel = 'orden de cambio';

    protected static ?string $pluralModelLabel = 'órdenes de cambio';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-plus';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forUser(Auth::user())->with(['jobSite', 'jobScope', 'requestedBy']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('job_site_id')->label('Obra')->required(fn (string $operation): bool => $operation === 'create')->searchable()->live()
                ->options(fn (): array => JobSite::visibleTo(Auth::user())->orderBy('name')->pluck('name', 'id')->all())
                ->disabledOn('edit'),
            Select::make('job_scope_id')->label('Alcance')->searchable()->live()
                ->options(fn (Get $get): array => $get('job_site_id')
                    ? JobScope::query()->where('job_site_id', $get('job_site_id'))->orderBy('name')->pluck('name', 'id')->all()
                    : [])
                ->disabledOn('edit'),
            TextInput::make('title')->label('Título')->required()->maxLength(200),
            Textarea::make('description')->label('Descripción')->required()->maxLength(5000)->columnSpanFull(),
            TextInput::make('amount_cents')->label('Monto (centavos USD)')->required()->integer()
                ->helperText('Entero con signo. 150000 son $1,500. Un valor negativo es un crédito. Filament 4 no incluye MoneyInput, así que el formulario guarda centavos directamente.'),
            TextInput::make('time_impact_days')->label('Impacto en días')->required()->integer()->default(0)->minValue(-3650)->maxValue(3650),
            Textarea::make('schedule_impact_note')->label('Nota de plazo')->maxLength(2000)->columnSpanFull(),
            FileUpload::make('uploads')->label('Adjuntos')->multiple()->storeFiles(false)->disk('s3')->visibility('private')
                ->preventFilePathTampering()->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(20480)->maxFiles(5)
                ->helperText('PDF, JPEG o PNG. Hasta 5 archivos de 20 MB. El archivo temporal no se publica.')
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Código'),
            TextEntry::make('title')->label('Título'),
            TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn (ChangeOrderStatus $state): string => $state->label()),
            TextEntry::make('amount_cents')->label('Monto')->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
            TextEntry::make('time_impact_days')->label('Días'),
            TextEntry::make('jobSite.name')->label('Obra'),
            TextEntry::make('jobScope.name')->label('Alcance')->placeholder('Toda la obra'),
            TextEntry::make('requestedBy.name')->label('Solicitada por'),
            TextEntry::make('submitted_at')->label('Enviada')->dateTime('d/m/Y H:i', 'America/New_York')->placeholder('—'),
            TextEntry::make('reviewedBy.name')->label('Revisada por')->placeholder('—'),
            TextEntry::make('reviewed_at')->label('Revisión')->dateTime('d/m/Y H:i', 'America/New_York')->placeholder('—'),
            TextEntry::make('approvedBy.name')->label('Aprobada por')->placeholder('—'),
            TextEntry::make('approved_at')->label('Aprobación')->dateTime('d/m/Y H:i', 'America/New_York')->placeholder('—'),
            TextEntry::make('rejected_at')->label('Rechazo')->dateTime('d/m/Y H:i', 'America/New_York')->placeholder('—'),
            TextEntry::make('rejection_reason')->label('Motivo de rechazo')->placeholder('—')->columnSpanFull(),
            TextEntry::make('cancelled_reason')->label('Motivo de cancelación')->placeholder('—')->columnSpanFull(),
            TextEntry::make('description')->label('Descripción')->columnSpanFull(),
            RepeatableEntry::make('attachments')->label('Adjuntos')->schema([
                TextEntry::make('original_name')->label('Archivo'),
                TextEntry::make('url')->label('Enlace')->state(fn ($record): ?string => $record->temporaryUrl())
                    ->url(fn (?string $state): ?string => $state)->placeholder('No disponible'),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('Código')->searchable(),
            TextColumn::make('title')->label('Título')->searchable()->limit(40),
            TextColumn::make('jobSite.name')->label('Obra'),
            TextColumn::make('jobScope.name')->label('Alcance')->placeholder('Obra'),
            TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (ChangeOrderStatus $state): string => $state->label()),
            TextColumn::make('amount_cents')->label('Monto')->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))->sortable(),
            TextColumn::make('time_impact_days')->label('Días'),
            TextColumn::make('requestedBy.name')->label('Solicitante'),
            TextColumn::make('submitted_at')->label('Enviada')->dateTime('d/m/Y H:i', 'America/New_York')->placeholder('—'),
        ])->filters([
            SelectFilter::make('job_site_id')->label('Obra')->relationship('jobSite', 'name'),
            SelectFilter::make('job_scope_id')->label('Alcance')->relationship('jobScope', 'name'),
            SelectFilter::make('status')->label('Estado')->options(collect(ChangeOrderStatus::cases())->mapWithKeys(fn (ChangeOrderStatus $status) => [$status->value => $status->label()])),
            Filter::make('submitted')->label('Enviadas desde')->schema([
                DatePicker::make('from')->label('Desde'),
                DatePicker::make('until')->label('Hasta'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $orders, string $date) => $orders->whereDate('submitted_at', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $orders, string $date) => $orders->whereDate('submitted_at', '<=', $date))),
            Filter::make('amount')->label('Monto (USD)')->schema([
                TextInput::make('min')->numeric()->label('Mínimo'),
                TextInput::make('max')->numeric()->label('Máximo'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['min'] ?? null, fn (Builder $orders, $min) => $orders->where('amount_cents', '>=', (int) round(((float) $min) * 100)))
                ->when($data['max'] ?? null, fn (Builder $orders, $max) => $orders->where('amount_cents', '<=', (int) round(((float) $max) * 100)))),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            ...self::transitionActions(),
        ]);
    }

    public static function transitionActions(): array
    {
        return [
            self::simpleAction('submit', 'Enviar', fn (ChangeOrder $order) => app(ChangeOrderService::class)->submit($order, Auth::user())),
            self::simpleAction('startReview', 'Iniciar revisión', fn (ChangeOrder $order) => app(ChangeOrderService::class)->startReview($order, Auth::user())),
            Action::make('approve')->label('Aprobar')->visible(fn (ChangeOrder $order): bool => Auth::user()->can('approve', $order))
                ->requiresConfirmation()
                ->schema([Textarea::make('note')->label('Nota')->maxLength(2000)])
                ->action(fn (ChangeOrder $order, array $data) => self::run(fn () => app(ChangeOrderService::class)->approve($order, Auth::user(), $data['note'] ?? null))),
            Action::make('reject')->label('Rechazar')->visible(fn (ChangeOrder $order): bool => Auth::user()->can('reject', $order))
                ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength(2000)])
                ->action(fn (ChangeOrder $order, array $data) => self::run(fn () => app(ChangeOrderService::class)->reject($order, Auth::user(), $data['reason']))),
            Action::make('cancel')->label('Cancelar')->visible(fn (ChangeOrder $order): bool => Auth::user()->can('cancel', $order))
                ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength(2000)])
                ->action(fn (ChangeOrder $order, array $data) => self::run(fn () => app(ChangeOrderService::class)->cancel($order, Auth::user(), $data['reason']))),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChangeOrders::route('/'),
            'create' => CreateChangeOrder::route('/create'),
            'view' => ViewChangeOrder::route('/{record}'),
            'edit' => EditChangeOrder::route('/{record}/edit'),
        ];
    }

    private static function simpleAction(string $name, string $label, callable $callback): Action
    {
        return Action::make($name)->label($label)
            ->visible(fn (ChangeOrder $order): bool => Auth::user()->can($name, $order))
            ->requiresConfirmation()
            ->action(fn (ChangeOrder $order) => self::run(fn () => $callback($order)));
    }

    private static function run(callable $callback): void
    {
        try {
            $callback();
            Notification::make()->title('Orden actualizada')->success()->send();
        } catch (InvalidChangeOrderTransitionException|AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }
}
