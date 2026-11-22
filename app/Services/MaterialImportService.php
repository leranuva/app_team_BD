<?php

namespace App\Services;

use App\Models\Material;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use League\Csv\Exception;
use League\Csv\Reader;

class MaterialImportService
{
    public const DOWNLOAD_URL = 'https://github.com/datadrivenconstruction/openconstructionerp/blob/main/data/catalog/cwicr_materials.csv';

    public const CHUNK_SIZE = 500;

    /** @var list<string> */
    private array $overlongCodes = [];

    /**
     * Columnas de precio, moneda, uso y región del CSV se ignoran.
     * Un code repetido en el mismo archivo se aplica en orden: la última fila gana.
     *
     * @return array{inserted: int, updated: int, skipped: int, total: int}
     */
    public function import(string $filePath, bool $dryRun = false, bool $truncate = false, ?callable $onProgress = null): array
    {
        $csv = $this->reader($filePath);

        $this->overlongCodes = [];

        $process = function () use ($csv, $dryRun, $truncate, $onProgress): array {
            if ($truncate && ! $dryRun) {
                Material::withoutManualAudit(fn () => Material::query()->delete());
            }

            $known = array_fill_keys(
                $truncate ? [] : Material::query()->pluck('code')->all(),
                true,
            );
            $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
            $chunk = [];
            $rowNumber = 1;

            foreach ($csv->getRecords() as $record) {
                $rowNumber++;
                $chunk[] = [$rowNumber, $record];
                if (count($chunk) >= self::CHUNK_SIZE) {
                    $this->applyChunk($chunk, $known, $counts, $dryRun);
                    if ($onProgress !== null) {
                        $onProgress(count($chunk));
                    }
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->applyChunk($chunk, $known, $counts, $dryRun);
                if ($onProgress !== null) {
                    $onProgress(count($chunk));
                }
            }

            $overlong = count($this->overlongCodes);
            if ($overlong > 0) {
                Log::warning(
                    $overlong === 1 ? '1 fila omitida por longitud' : $overlong.' filas omitidas por longitud',
                    ['codes' => $this->overlongCodes],
                );
            }

            $counts['total'] = $counts['inserted'] + $counts['updated'] + $counts['skipped'];
            Log::info('Importación de catálogo CWICR', $counts + ['dry_run' => $dryRun, 'truncate' => $truncate]);

            return $counts;
        };

        return $dryRun ? $process() : DB::transaction($process);
    }

    public function countDataRows(string $filePath): int
    {
        $count = 0;
        foreach ($this->reader($filePath)->getRecords() as $record) {
            unset($record);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array{0: int, 1: array<string, string|null>}>  $chunk
     * @param  array<string, true>  $known
     * @param  array{inserted: int, updated: int, skipped: int}  $counts
     */
    private function applyChunk(array $chunk, array &$known, array &$counts, bool $dryRun): void
    {
        $write = function () use ($chunk, &$known, &$counts, $dryRun): void {
            foreach ($chunk as [$rowNumber, $record]) {
                $prepared = $this->prepare($record, $rowNumber);
                if ($prepared === null) {
                    $counts['skipped']++;

                    continue;
                }

                $now = now();
                // La fila anterior del mismo code ya se insertó o ya estaba en la tabla. Esta la pisa.
                if (isset($known[$prepared['code']])) {
                    if (! $dryRun) {
                        Material::query()->where('code', $prepared['code'])->update([
                            'name' => $prepared['name'],
                            'unit' => $prepared['unit'],
                            'category' => $prepared['category'],
                            'updated_at' => $now,
                        ]);
                    }
                    $counts['updated']++;

                    continue;
                }

                $known[$prepared['code']] = true;
                $counts['inserted']++;
                if (! $dryRun) {
                    Material::query()->insert([
                        'code' => $prepared['code'],
                        'name' => $prepared['name'],
                        'unit' => $prepared['unit'],
                        'category' => $prepared['category'],
                        'is_active' => true,
                        'notes' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        };

        if ($dryRun) {
            $write();

            return;
        }

        Material::withoutManualAudit($write);
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array{code: string, name: string, unit: string, category: string}|null
     */
    private function prepare(array $record, int $rowNumber): ?array
    {
        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $code = trim((string) ($record['code'] ?? ''));
        $unit = trim((string) ($record['unit'] ?? ''));
        $category = trim((string) ($record['category'] ?? ''));
        if ($category === '') {
            $category = 'General';
        }
        if ($unit === '') {
            $unit = 'pcs';
        }

        $values = ['code' => $code, 'name' => $name, 'unit' => $unit, 'category' => $category];
        $limits = ['code' => 255, 'name' => 255, 'unit' => 20, 'category' => 100];
        $tooLong = false;
        foreach ($limits as $field => $limit) {
            if ($values[$field] !== '' && mb_strlen($values[$field]) > $limit) {
                $tooLong = true;
                Log::warning('Fila de catálogo omitida por superar el límite de columna', [
                    'row' => $rowNumber,
                    'code' => $code,
                    'field' => $field,
                    'length' => mb_strlen($values[$field]),
                    'limit' => $limit,
                ]);
            }
        }
        if ($tooLong) {
            $this->overlongCodes[] = $code;

            return null;
        }

        if ($code === '') {
            $code = 'MAT-'.substr(sha1($name.'|'.$unit.'|'.$category), 0, 10);
        }

        return compact('code', 'name', 'unit', 'category');
    }

    private function reader(string $filePath): Reader
    {
        if (! is_file($filePath)) {
            throw new InvalidArgumentException(
                'No se encontró el CSV en '.$filePath.'. Descárgalo desde '.self::DOWNLOAD_URL
                .' y colócalo en database/data/cwicr_materials.csv.'
            );
        }

        try {
            $csv = Reader::createFromPath($filePath);
            $csv->setHeaderOffset(0);
            $header = array_map(trim(...), $csv->getHeader());
        } catch (Exception $exception) {
            throw new InvalidArgumentException('El CSV no tiene una cabecera legible.', previous: $exception);
        }

        $missing = array_diff(['code', 'name', 'category', 'unit'], $header);
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'El CSV debe incluir code, name, category y unit. Columnas encontradas: '.implode(', ', $header).'.'
            );
        }

        return $csv;
    }
}
