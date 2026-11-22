<?php

namespace App\Console\Commands;

use App\Models\Material;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use League\Csv\Exception;
use League\Csv\Reader;

class ImportSheetrockMaterials extends Command
{
    protected $signature = 'materials:import-sheetrock
        {file? : Ruta al CSV (default: database/data/nyc_sheetrock_types_catalog_en.csv)}';

    protected $description = 'Importa el catálogo de placas de Nueva York en la categoría Drywall';

    public function handle(): int
    {
        $path = $this->argument('file') ?: database_path('data/nyc_sheetrock_types_catalog_en.csv');

        try {
            $result = $this->import($path);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '[OK] Importados: %d · Actualizados: %d · Omitidos: %d · Total: %d',
            $result['inserted'],
            $result['updated'],
            $result['skipped'],
            $result['total'],
        ));

        return self::SUCCESS;
    }

    /**
     * El precio del CSV no se guarda. El subtipo, el espesor y el uso van en notes.
     * La categoría es siempre Drywall, no el valor de Sheetrock_Category.
     *
     * @return array{inserted: int, updated: int, skipped: int, total: int}
     */
    public function import(string $filePath): array
    {
        $csv = $this->reader($filePath);
        $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($csv, &$counts): void {
            Material::withoutManualAudit(function () use ($csv, &$counts): void {
                $known = array_fill_keys(Material::query()->pluck('code')->all(), true);

                foreach ($csv->getRecords() as $record) {
                    $prepared = $this->prepare($record);
                    if ($prepared === null) {
                        $counts['skipped']++;

                        continue;
                    }

                    $now = now();
                    if (isset($known[$prepared['code']])) {
                        Material::query()->where('code', $prepared['code'])->update([
                            'name' => $prepared['name'],
                            'unit' => $prepared['unit'],
                            'category' => 'Drywall',
                            'notes' => $prepared['notes'],
                            'updated_at' => $now,
                        ]);
                        $counts['updated']++;

                        continue;
                    }

                    $known[$prepared['code']] = true;
                    $counts['inserted']++;
                    Material::query()->insert([
                        'code' => $prepared['code'],
                        'name' => $prepared['name'],
                        'unit' => $prepared['unit'],
                        'category' => 'Drywall',
                        'is_active' => true,
                        'notes' => $prepared['notes'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        });

        $counts['total'] = $counts['inserted'] + $counts['updated'] + $counts['skipped'];

        return $counts;
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array{code: string, name: string, unit: string, notes: string}|null
     */
    private function prepare(array $record): ?array
    {
        $code = trim((string) ($record['SKU'] ?? ''));
        $name = trim((string) ($record['Material_Name'] ?? ''));
        $unit = trim((string) ($record['Unit'] ?? ''));
        if ($code === '' || $name === '' || mb_strlen($code) > 255 || mb_strlen($name) > 255) {
            return null;
        }
        if ($unit === '') {
            $unit = 'Sheet';
        }
        if (mb_strlen($unit) > (int) config('materials.unit_max_length')) {
            return null;
        }

        $notes = collect([
            $record['Sheetrock_Category'] ?? null,
            $record['Thickness'] ?? null,
            $record['Dimensions'] ?? null,
            $record['Application_&_Specs'] ?? null,
            $record['NYC_Suppliers'] ?? null,
        ])->map(fn (mixed $value): string => trim((string) $value))->filter()->implode(' · ');

        return compact('code', 'name', 'unit', 'notes');
    }

    private function reader(string $filePath): Reader
    {
        if (! is_file($filePath)) {
            throw new InvalidArgumentException('No se encontró el CSV en '.$filePath.'.');
        }

        try {
            $csv = Reader::createFromPath($filePath);
            $csv->setHeaderOffset(0);
            $header = array_map(trim(...), $csv->getHeader());
        } catch (Exception $exception) {
            throw new InvalidArgumentException('El CSV no tiene una cabecera legible.', previous: $exception);
        }

        $missing = array_diff(['SKU', 'Material_Name', 'Unit'], $header);
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'El CSV debe incluir SKU, Material_Name y Unit. Columnas encontradas: '.implode(', ', $header).'.'
            );
        }

        return $csv;
    }
}
