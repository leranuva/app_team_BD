<?php

namespace App\Filament\Pages;

use App\Models\Material;
use App\Services\MaterialImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ImportMaterials extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import materials';

    protected static ?string $title = 'Import materials';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('import', Material::class) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import')
                ->schema([
                    FileUpload::make('file')->label('CSV CWICR')->required()->storeFiles(false)
                        ->helperText('Updates name, unit and category. Keeps the catalog, active status and notes.'),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'] ?? null;
                    if (is_array($file)) {
                        $file = Arr::first($file);
                    }

                    $path = $file instanceof UploadedFile ? $file->getRealPath() : (is_string($file) ? $file : null);
                    if (! is_string($path) || ! is_file($path)) {
                        Notification::make()->title('Select a CSV file.')->danger()->send();

                        return;
                    }

                    try {
                        $result = app(MaterialImportService::class)->import($path, false, false);
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(sprintf(
                        'Imported: %d · Updated: %d · Skipped: %d',
                        $result['inserted'],
                        $result['updated'],
                        $result['skipped'],
                    ))->success()->send();
                }),
        ];
    }
}
