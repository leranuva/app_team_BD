<?php

namespace App\Console\Commands;

use App\Services\MaterialImportService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ImportCwicrMaterials extends Command
{
    protected $signature = 'materials:import-cwicr
        {file? : Ruta al CSV (default: database/data/cwicr_materials.csv)}
        {--dry-run : Count new, updated and skipped rows without writing changes}
        {--truncate : Clear the catalog before importing}
        {--force : Allow --truncate without confirmation}';

    protected $description = 'Import the CWICR catalog into materials without prices or per-row audit entries';

    public function handle(MaterialImportService $importer): int
    {
        $truncate = (bool) $this->option('truncate');
        $dryRun = (bool) $this->option('dry-run');

        if ($truncate && ! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('This will delete all materials. Continue?')) {
                $this->error('Import canceled. Pass --force to clear the catalog without confirmation.');

                return self::FAILURE;
            }
        }

        $path = $this->argument('file') ?: database_path('data/cwicr_materials.csv');
        $progress = false;

        try {
            if ($this->output->isDecorated()) {
                $this->output->progressStart($importer->countDataRows($path));
                $progress = true;
            }

            $result = $importer->import(
                $path,
                $dryRun,
                $truncate,
                $progress ? fn (int $step) => $this->output->progressAdvance($step) : null,
            );
        } catch (InvalidArgumentException $exception) {
            if ($progress) {
                $this->output->progressFinish();
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException) {
            if ($progress) {
                $this->output->progressFinish();
            }
            $referenced = (int) DB::table('material_list_items')->distinct()->count('material_id');
            $this->error($referenced > 0
                ? 'Cannot truncate: '.$this->referencedMaterials($referenced).'. Delete those lists or run the import without --truncate to update the catalog.'
                : 'The import was rolled back due to a database error.');

            return self::FAILURE;
        }

        if ($progress) {
            $this->output->progressFinish();
            $this->newLine();
        }

        $this->info(sprintf(
            '[OK] Imported: %d · Updated: %d · Skipped: %d · Total: %d',
            $result['inserted'],
            $result['updated'],
            $result['skipped'],
            $result['total'],
        ));

        return self::SUCCESS;
    }

    private function referencedMaterials(int $count): string
    {
        return $count === 1
            ? '1 material is referenced by lists'
            : $count.' materials are referenced by lists';
    }
}
