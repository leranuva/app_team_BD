<?php

use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Services\MaterialImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('imports the sample from the command', function (): void {
    $this->artisan('materials:import-cwicr', [
        'file' => database_path('data/cwicr_materials_sample.csv'),
    ])->assertSuccessful()->expectsOutputToContain('[OK] Importados: 10 · Actualizados: 0 · Omitidos: 0 · Total: 10');

    expect(Material::query()->count())->toBe(10)
        ->and(Material::active()->count())->toBe(10)
        ->and(Activity::query()->where('log_name', 'materials')->count())->toBe(0);
});

it('does not persist a dry run from the command', function (): void {
    $this->artisan('materials:import-cwicr', [
        'file' => database_path('data/cwicr_materials_sample.csv'),
        '--dry-run' => true,
    ])->assertSuccessful()->expectsOutputToContain('Importados: 10');

    expect(Material::query()->count())->toBe(0);
});

it('truncates and imports when force is present', function (): void {
    Material::factory()->create(['code' => 'EXTRA']);

    $this->artisan('materials:import-cwicr', [
        'file' => database_path('data/cwicr_materials_sample.csv'),
        '--truncate' => true,
        '--force' => true,
    ])->assertSuccessful()->expectsOutputToContain('Importados: 10');

    expect(Material::query()->where('code', 'EXTRA')->exists())->toBeFalse()
        ->and(Material::query()->count())->toBe(10);
});

it('refuses to truncate without force when nobody can confirm', function (): void {
    Material::factory()->create(['code' => 'EXTRA']);

    $code = Artisan::call('materials:import-cwicr', [
        'file' => database_path('data/cwicr_materials_sample.csv'),
        '--truncate' => true,
        '--no-interaction' => true,
    ]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('Importación cancelada')
        ->and(Material::query()->where('code', 'EXTRA')->exists())->toBeTrue();
});

it('explains a truncate blocked by material lists', function (): void {
    $material = Material::factory()->create(['code' => 'KEEP']);
    $list = MaterialList::query()->create([
        'job_site_id' => JobSite::factory()->create()->id,
        'name' => 'Lista vieja',
        'status' => 'pending',
    ]);
    MaterialListItem::query()->create([
        'material_list_id' => $list->id,
        'material_id' => $material->id,
        'budgeted_quantity' => 2,
    ]);

    $this->artisan('materials:import-cwicr', [
        'file' => database_path('data/cwicr_materials_sample.csv'),
        '--truncate' => true,
        '--force' => true,
    ])->assertFailed()->expectsOutputToContain('No se puede truncar: 1 material está referenciado por listas. Elimina esas listas o importa sin --truncate');

    expect(Material::query()->where('code', 'KEEP')->exists())->toBeTrue()
        ->and(Material::query()->count())->toBe(1);
});

it('fails with the download url when the csv is missing', function (): void {
    $this->artisan('materials:import-cwicr', [
        'file' => storage_path('app/no-existe-cwicr.csv'),
    ])->assertFailed()->expectsOutputToContain(MaterialImportService::DOWNLOAD_URL);

    expect(Material::query()->count())->toBe(0);
});
