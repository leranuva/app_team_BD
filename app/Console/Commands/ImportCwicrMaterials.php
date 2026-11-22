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
        {--dry-run : Cuenta altas, cambios y omisiones sin escribir}
        {--truncate : Borra el catálogo antes de importar}
        {--force : Permite --truncate sin confirmación}';

    protected $description = 'Importa el catálogo CWICR en materials, sin precios ni auditoría por fila';

    public function handle(MaterialImportService $importer): int
    {
        $truncate = (bool) $this->option('truncate');
        $dryRun = (bool) $this->option('dry-run');

        if ($truncate && ! $dryRun && ! $this->option('force')) {
            if (! $this->confirm('Esto borrará todos los materiales. ¿Continuar?')) {
                $this->error('Importación cancelada. Pasa --force para borrar el catálogo sin confirmación.');

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
                ? 'No se puede truncar: '.$this->referencedMaterials($referenced).'. Elimina esas listas o importa sin --truncate para actualizar el catálogo sin borrarlo.'
                : 'La importación se revirtió por un error de base de datos.');

            return self::FAILURE;
        }

        if ($progress) {
            $this->output->progressFinish();
            $this->newLine();
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

    private function referencedMaterials(int $count): string
    {
        return $count === 1
            ? '1 material está referenciado por listas'
            : $count.' materiales están referenciados por listas';
    }
}
