<?php

namespace App\Filament\Resources\Materials;

use App\Filament\Resources\Materials\Pages\CreateMaterial;
use App\Filament\Resources\Materials\Pages\EditMaterial;
use App\Filament\Resources\Materials\Pages\ListMaterials;
use App\Filament\Resources\Materials\Pages\ViewMaterial;
use App\Models\Material;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class MaterialResource extends Resource
{
    public const OTHER = '__other__';

    protected static ?string $model = Material::class;

    protected static ?string $modelLabel = 'material';

    protected static ?string $pluralModelLabel = 'materials';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('listItems');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            self::referencePhoto(),
            TextInput::make('code')->label('Code')->required()->maxLength(255)
                ->unique(ignoreRecord: true)
                ->disabledOn('edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->helperText('The CWICR code cannot be changed after creation.'),
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            self::catalogChoice('unit', 'Unit', 'materials.units', (int) config('materials.unit_max_length')),
            self::catalogCustom('unit', 'Other unit', (int) config('materials.unit_max_length')),
            self::catalogChoice('category', 'Category', 'materials.categories', 100),
            self::catalogCustom('category', 'Other category', 100),
            Toggle::make('is_active')->label('Active')->default(true),
            Textarea::make('notes')->label('Notes')->maxLength(2000)->columnSpanFull()
                ->helperText('Custom specifications. CWICR imports preserve this text.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            self::referencePhoto(),
            TextEntry::make('code')->label('Code'),
            TextEntry::make('name')->label('Name'),
            TextEntry::make('unit')->label('Unit'),
            TextEntry::make('category')->label('Category'),
            TextEntry::make('is_active')->label('Active')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            TextEntry::make('list_items_count')->label('Lists'),
            TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            TextEntry::make('updated_at')->label('Updated')->dateTime('m/d/Y H:i', 'America/New_York'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('reference_photo')->label('Photo')
                ->state(fn (Material $record): ?string => $record->referencePhotoUrl())
                ->imageHeight(48),
            TextColumn::make('code')->label('Code')->searchable()->sortable(),
            TextColumn::make('name')->label('Name')->searchable()->sortable()->limit(48),
            TextColumn::make('unit')->label('Unit'),
            TextColumn::make('category')->label('Category')->sortable(),
            TextColumn::make('is_active')->label('Active')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            TextColumn::make('list_items_count')->label('Lists')->sortable(),
            TextColumn::make('updated_at')->label('Updated')->dateTime('m/d/Y H:i', 'America/New_York')->sortable(),
        ])->filters([
            SelectFilter::make('category')->label('Category')->options(fn (): array => self::choiceList(
                array_merge(config('materials.categories'), Material::query()->distinct()->orderBy('category')->pluck('category')->all()),
            )),
            TernaryFilter::make('is_active')->label('Active'),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            self::deleteAction(),
        ])->toolbarActions([
            BulkAction::make('deactivate')->label('Deactivate')->color('warning')->requiresConfirmation()
                ->visible(fn (): bool => Auth::user()->can('create', Material::class))
                ->action(fn (Collection $records) => self::setActive($records, false)),
            BulkAction::make('activate')->label('Activate')->requiresConfirmation()
                ->visible(fn (): bool => Auth::user()->can('create', Material::class))
                ->action(fn (Collection $records) => self::setActive($records, true)),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaterials::route('/'),
            'create' => CreateMaterial::route('/create'),
            'view' => ViewMaterial::route('/{record}'),
            'edit' => EditMaterial::route('/{record}/edit'),
        ];
    }

    private static function referencePhoto(): ImageEntry
    {
        return ImageEntry::make('reference_photo')->label('Reference photo')
            ->state(fn (?Material $record): ?string => $record?->referencePhotoUrl())
            ->visible(fn (?Material $record): bool => $record?->referencePhotoUrl() !== null)
            ->url(fn (?Material $record): ?string => $record?->referencePhotoUrl(), shouldOpenInNewTab: true)
            ->imageHeight(220)->columnSpanFull();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mapCatalogFields(array $data): array
    {
        foreach (['unit' => (int) config('materials.unit_max_length'), 'category' => 100] as $field => $max) {
            if (! array_key_exists($field.'_choice', $data)) {
                continue;
            }

            $choice = $data[$field.'_choice'];
            $custom = trim((string) ($data[$field.'_custom'] ?? ''));
            $data[$field] = $choice === self::OTHER ? mb_substr($custom, 0, $max) : (string) $choice;
            unset($data[$field.'_choice'], $data[$field.'_custom']);
        }

        return $data;
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->authorize(fn (): bool => (bool) Auth::user()?->can('create', Material::class))
            ->action(function (Material $record): void {
                if (! Auth::user()->can('delete', $record)) {
                    Notification::make()
                        ->title('This material is used in a list. Deactivate it instead of deleting it.')
                        ->danger()
                        ->send();

                    return;
                }

                $record->delete();
                Notification::make()->title('Material deleted')->success()->send();
            });
    }

    private static function catalogChoice(string $field, string $label, string $configKey, int $max): Select
    {
        return Select::make($field.'_choice')->label($label)->required()->live()->searchable()
            ->options(fn (): array => [...self::choiceList(config($configKey)), self::OTHER => 'Other'])
            ->afterStateHydrated(function (Select $component, ?Material $record) use ($field, $configKey): void {
                if ($record === null) {
                    return;
                }

                $current = (string) $record->getAttribute($field);
                $component->state(in_array($current, config($configKey), true) ? $current : self::OTHER);
            })
            ->helperText($field === 'category'
                ? 'Select “Other” to enter a custom category up to '.$max.' characters.'
                : 'Select “Other” to enter a custom unit up to '.$max.' characters.');
    }

    private static function catalogCustom(string $field, string $label, int $max): TextInput
    {
        return TextInput::make($field.'_custom')->label($label)->maxLength($max)
            ->visible(fn (Get $get): bool => $get($field.'_choice') === self::OTHER)
            ->required(fn (Get $get): bool => $get($field.'_choice') === self::OTHER)
            ->afterStateHydrated(function (TextInput $component, ?Material $record) use ($field, $max): void {
                if ($record === null) {
                    return;
                }

                $current = (string) $record->getAttribute($field);
                $known = $field === 'unit' ? config('materials.units') : config('materials.categories');
                if (! in_array($current, $known, true)) {
                    $component->state(mb_substr($current, 0, $max));
                }
            });
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function choiceList(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, fn (string $value): bool => $value !== '')));

        return array_combine($values, $values);
    }

    private static function setActive(Collection $records, bool $active): void
    {
        abort_unless(Auth::user()->can('create', Material::class), 403);

        $records->each(function (Material $material) use ($active): void {
            abort_unless(Auth::user()->can('update', $material), 403);
            $material->update(['is_active' => $active]);
        });
    }
}
