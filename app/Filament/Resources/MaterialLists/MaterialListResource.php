<?php

namespace App\Filament\Resources\MaterialLists;

use App\Enums\MaterialListStatus;
use App\Exceptions\InvalidMaterialListTransitionException;
use App\Filament\Resources\MaterialLists\Pages\CreateMaterialList;
use App\Filament\Resources\MaterialLists\Pages\EditMaterialList;
use App\Filament\Resources\MaterialLists\Pages\ListMaterialLists;
use App\Filament\Resources\MaterialLists\Pages\ViewMaterialList;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Services\MaterialListService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MaterialListResource extends Resource
{
    protected static ?string $model = MaterialList::class;

    protected static ?string $modelLabel = 'lista de materiales';

    protected static ?string $pluralModelLabel = 'listas de materiales';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forUser(Auth::user())->with(['jobSite', 'jobScope', 'requestedBy', 'items']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('job_site_id')->label('Obra')->searchable()->live()
                ->options(fn (): array => JobSite::query()->orderBy('name')->pluck('name', 'id')->all())
                ->disabledOn('edit')->dehydrated(),
            Select::make('job_scope_id')->label('Alcance')->required()->searchable()->live()
                ->options(fn (Get $get): array => $get('job_site_id')
                    ? JobScope::query()->where('job_site_id', $get('job_site_id'))->orderBy('name')->pluck('name', 'id')->all()
                    : JobScope::query()->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $siteId = $state === null ? null : JobScope::query()->whereKey($state)->value('job_site_id');
                    if ($siteId !== null) {
                        $set('job_site_id', $siteId);
                    }
                })
                ->disabledOn('edit'),
            TextInput::make('title')->label('Título')->required()->maxLength(200),
            Textarea::make('notes')->label('Notas')->maxLength(5000)->columnSpanFull(),
            Repeater::make('items')->label('Materiales')->columnSpanFull()->minItems(1)->schema([
                Select::make('material_id')->label('Material')->required()->searchable()
                    ->options(fn (): array => Material::query()->active()->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('budgeted_quantity')->label('Presupuestada')->numeric()->default(0)->minValue(0),
                TextInput::make('requested_quantity')->label('Solicitada')->numeric()->required()->minValue(0),
                TextInput::make('unit_cost_cents')->label('Costo (centavos)')->numeric()->integer()->minValue(0)
                    ->helperText('Opcional. Es un apunte de la factura, no un cálculo.'),
                Textarea::make('notes')->label('Notas')->maxLength(2000),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Código'),
            TextEntry::make('title')->label('Título'),
            TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn (MaterialListStatus $state): string => $state->label()),
            TextEntry::make('jobSite.name')->label('Obra'),
            TextEntry::make('jobScope.name')->label('Alcance'),
            TextEntry::make('requestedBy.name')->label('Solicitada por'),
            TextEntry::make('approvedBy.name')->label('Aprobada por')->placeholder('—'),
            TextEntry::make('ordered_at')->label('Ordenada')->dateTime('d/m/Y H:i', 'America/New_York')->placeholder('—'),
            TextEntry::make('delivered_at')->label('Entregada')->dateTime('d/m/Y H:i', 'America/New_York')->placeholder('—'),
            TextEntry::make('consumed_at')->label('Consumida')->dateTime('d/m/Y H:i', 'America/New_York')->placeholder('—'),
            TextEntry::make('cancelled_reason')->label('Motivo de cancelación')->placeholder('—')->columnSpanFull(),
            TextEntry::make('notes')->label('Notas')->placeholder('—')->columnSpanFull(),
            RepeatableEntry::make('items')->label('Ítems')->schema([
                TextEntry::make('material.name')->label('Material'),
                TextEntry::make('budgeted_quantity')->label('Presupuestada'),
                TextEntry::make('requested_quantity')->label('Solicitada'),
                TextEntry::make('approved_quantity')->label('Aprobada')->placeholder('—'),
                TextEntry::make('delivered_quantity')->label('Entregada')->placeholder('—'),
                TextEntry::make('consumed_quantity')->label('Consumida')->placeholder('—'),
                TextEntry::make('unit_cost_cents')->label('Costo')->placeholder('—'),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('Código')->searchable(),
            TextColumn::make('title')->label('Título')->searchable()->limit(40),
            TextColumn::make('jobScope.name')->label('Alcance'),
            TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (MaterialListStatus $state): string => $state->label()),
            TextColumn::make('requestedBy.name')->label('Solicitante'),
            TextColumn::make('total')->label('Total solicitado')->state(function (MaterialList $record): string {
                $total = $record->totalRequestedValueCents();

                return $total === null ? 'sin costos' : '$'.number_format($total / 100, 2);
            }),
            TextColumn::make('created_at')->label('Creada')->dateTime('d/m/Y H:i', 'America/New_York'),
        ])->filters([
            SelectFilter::make('job_site_id')->label('Obra')->relationship('jobSite', 'name'),
            SelectFilter::make('job_scope_id')->label('Alcance')->relationship('jobScope', 'name'),
            SelectFilter::make('status')->label('Estado')->options(collect(MaterialListStatus::cases())->mapWithKeys(fn (MaterialListStatus $status) => [$status->value => $status->label()])),
            SelectFilter::make('requested_by')->label('Solicitante')->relationship('requestedBy', 'name'),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            ...self::transitionActions(),
        ]);
    }

    public static function transitionActions(): array
    {
        return [
            self::simpleAction('submit', 'Enviar', fn (MaterialList $list) => app(MaterialListService::class)->submit($list, Auth::user())),
            self::quantityAction('approve', 'Aprobar', 'approve', fn (MaterialList $list, array $quantities) => app(MaterialListService::class)->approve($list, Auth::user(), $quantities)),
            self::simpleAction('markOrdered', 'Marcar ordenada', fn (MaterialList $list) => app(MaterialListService::class)->markOrdered($list, Auth::user())),
            self::quantityAction('registerDelivery', 'Registrar entrega', 'registerDelivery', fn (MaterialList $list, array $quantities, bool $confirm) => app(MaterialListService::class)->registerDelivery($list, Auth::user(), $quantities, $confirm), true),
            self::quantityAction('registerConsumption', 'Registrar consumo', 'registerConsumption', fn (MaterialList $list, array $quantities, bool $confirm) => app(MaterialListService::class)->registerConsumption($list, Auth::user(), $quantities, $confirm), true),
            Action::make('cancel')->label('Cancelar')->visible(fn (MaterialList $list): bool => Auth::user()->can('cancel', $list))
                ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength(2000)])
                ->action(fn (MaterialList $list, array $data) => self::run(fn () => app(MaterialListService::class)->cancel($list, Auth::user(), $data['reason']))),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaterialLists::route('/'),
            'create' => CreateMaterialList::route('/create'),
            'view' => ViewMaterialList::route('/{record}'),
            'edit' => EditMaterialList::route('/{record}/edit'),
        ];
    }

    private static function simpleAction(string $name, string $label, callable $callback): Action
    {
        return Action::make($name)->label($label)
            ->visible(fn (MaterialList $list): bool => Auth::user()->can($name, $list))
            ->requiresConfirmation()
            ->action(fn (MaterialList $list) => self::run(fn () => $callback($list)));
    }

    private static function quantityAction(string $name, string $label, string $ability, callable $callback, bool $confirm = false): Action
    {
        return Action::make($name)->label($label)
            ->visible(fn (MaterialList $list): bool => Auth::user()->can($ability, $list))
            ->schema([
                Repeater::make('lines')->label('Cantidades')->addable(false)->deletable(false)->schema([
                    Hidden::make('id'),
                    TextInput::make('label')->label('Material')->disabled()->dehydrated(false),
                    TextInput::make('quantity')->label('Cantidad')->required()->numeric()->minValue(0),
                ]),
                Checkbox::make('confirm')->label('Confirmar si la cantidad supera lo permitido')->visible($confirm),
            ])
            ->fillForm(fn (MaterialList $list): array => [
                'lines' => $list->items()->with('material')->get()->map(fn ($item): array => [
                    'id' => $item->id,
                    'label' => $item->material?->name,
                    'quantity' => (string) ($item->approved_quantity ?? $item->requested_quantity ?? '0'),
                ])->all(),
            ])
            ->action(function (MaterialList $list, array $data) use ($callback, $confirm): void {
                $quantities = [];
                foreach ($data['lines'] ?? [] as $line) {
                    $quantities[(int) $line['id']] = $line['quantity'];
                }
                self::run(fn () => $confirm
                    ? $callback($list, $quantities, (bool) ($data['confirm'] ?? false))
                    : $callback($list, $quantities));
            });
    }

    private static function run(callable $callback): void
    {
        try {
            $callback();
            Notification::make()->title('Lista actualizada')->success()->send();
        } catch (InvalidMaterialListTransitionException|AuthorizationException|ValidationException $exception) {
            $message = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : $exception->getMessage();
            Notification::make()->title((string) $message)->danger()->send();
        }
    }
}
