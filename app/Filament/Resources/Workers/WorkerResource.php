<?php

namespace App\Filament\Resources\Workers;

use App\Actions\ManageWorker;
use App\Filament\Resources\Workers\Pages\CreateWorker;
use App\Filament\Resources\Workers\Pages\EditWorker;
use App\Filament\Resources\Workers\Pages\ListWorkers;
use App\Models\JobSite;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Permission\Models\Role;

class WorkerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'workers';

    protected static ?string $modelLabel = 'trabajador';

    protected static ?string $pluralModelLabel = 'trabajadores';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->workersVisibleTo(Auth::user())->with(['roles', 'primaryJobSite']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contacto')->columns(2)->columnSpanFull()
                ->visible(fn (?User $record): bool => $record
                    ? Gate::allows('updateContactInfo', $record)
                    : Gate::allows('create', User::class))
                ->schema([
                    TextInput::make('phone')->label('Teléfono')->tel()->maxLength(40),
                    Textarea::make('address')->label('Dirección')->maxLength(500)->columnSpanFull(),
                    TextInput::make('emergency_contact.name')->label('Contacto de emergencia')->maxLength(255),
                    TextInput::make('emergency_contact.phone')->label('Teléfono de emergencia')->tel()->maxLength(40),
                ]),
            Section::make('Cuenta y asignación')->columns(2)->columnSpanFull()
                ->visible(fn (?User $record): bool => $record ? Gate::allows('manageWorkerProfile', $record) : Gate::allows('create', User::class))
                ->schema([
                    TextInput::make('name')->label('Nombre completo')->required()->maxLength(255),
                    TextInput::make('email')->label('Correo electrónico')->required()->email()->maxLength(255),
                    TextInput::make('password')->label('Contraseña inicial')->password()->required()
                        ->minLength(12)->maxLength(72)->visibleOn('create'),
                    Select::make('role_id')->label('Rol')->options(fn () => self::roles())->required(),
                    Select::make('primary_job_site_id')->label('Obra principal')->searchable()
                        ->getSearchResultsUsing(fn (string $search) => JobSite::whereLike('name', '%'.$search.'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value) => JobSite::find($value)?->name),
                    TextInput::make('hourly_rate')->label('Tarifa por hora (USD)')->numeric()->minValue(0)->maxValue(99999999.99)->step(0.01),
                    TextInput::make('insurance_reference')->label('Número de seguro o identificación')->maxLength(255),
                    self::documents('identity_documents', 'Documentos de identidad', 'identity'),
                    self::documents('osha_documents', 'Certificaciones OSHA', 'osha'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
            TextColumn::make('email')->label('Correo')->searchable(),
            TextColumn::make('roles.name')->label('Rol')->badge(),
            TextColumn::make('primaryJobSite.name')->label('Obra principal'),
            IconColumn::make('is_active')->label('Activo')->boolean(),
        ])->filters([
            SelectFilter::make('role')->label('Rol')->options(fn () => self::roles())
                ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $workers, $role) => $workers->whereHas('roles', fn (Builder $roles) => $roles->whereKey($role)))),
            SelectFilter::make('primary_job_site_id')->label('Obra principal')
                ->relationship('primaryJobSite', 'name')->searchable(),
            TernaryFilter::make('is_active')->label('Estado')->trueLabel('Activos')->falseLabel('Inactivos'),
        ])->recordActions([
            EditAction::make(),
            self::changeStatus(true),
            self::changeStatus(false),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkers::route('/'),
            'create' => CreateWorker::route('/create'),
            'edit' => EditWorker::route('/{record}/edit'),
        ];
    }

    private static function roles(): array
    {
        return Role::where('guard_name', 'web')->whereIn('name', User::operationalRoles())->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function documents(string $field, string $label, string $kind): FileUpload
    {
        return FileUpload::make($field)->label($label)->disk('s3')->directory("worker-documents/{$kind}")
            ->visibility('private')->multiple()->maxFiles(5)->maxSize(10240)
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
            ->saveUploadedFileUsing(fn (FileUpload $component, TemporaryUploadedFile $file): string => $file->storeAs(
                $component->getDirectory(),
                $component->getUploadedFileNameForStorage($file),
                ['disk' => $component->getDiskName(), 'visibility' => 'private', 'mimetype' => $file->getMimeType()],
            ))
            ->getUploadedFileUsing(function (string $file, ?User $record) use ($field): ?array {
                if (! $record || Gate::denies('manageWorkerProfile', $record)
                    || ! in_array($file, $record->getAttribute($field) ?? [], true)) {
                    return null;
                }

                return [
                    'name' => basename($file),
                    'size' => 0,
                    'type' => null,
                    'url' => Storage::disk('s3')->temporaryUrl($file, now()->addMinutes(10)),
                ];
            })
            ->preventFilePathTampering()->previewable(false)->openable()->columnSpanFull();
    }

    private static function changeStatus(bool $active): Action
    {
        return Action::make($active ? 'activate' : 'deactivate')->label($active ? 'Activar' : 'Desactivar')
            ->requiresConfirmation()
            ->visible(fn (User $record): bool => $record->is_active !== $active)
            ->authorize('manageWorkerProfile')
            ->action(fn (User $record) => app(ManageWorker::class)->setActive(Auth::user(), $record, $active));
    }
}
