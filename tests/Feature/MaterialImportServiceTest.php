<?php

use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Services\MaterialImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('imports the sample catalog once and skips the activity log', function (): void {
    $logged = false;
    Log::listen(function ($message) use (&$logged): void {
        $logged = $logged || ($message->level === 'info' && ($message->context['inserted'] ?? null) === 10);
    });

    $result = app(MaterialImportService::class)->import(sampleCatalog());

    expect($result)->toBe(['inserted' => 10, 'updated' => 0, 'skipped' => 0, 'total' => 10])
        ->and(Material::query()->count())->toBe(10)
        ->and(Material::active()->count())->toBe(10)
        ->and(Activity::query()->where('log_name', 'materials')->count())->toBe(0)
        ->and($logged)->toBeTrue();
});

it('updates the same codes without touching active state or notes', function (): void {
    $service = app(MaterialImportService::class);
    $service->import(sampleCatalog());
    Material::query()->where('code', 'MAT-0001')->firstOrFail()->update([
        'name' => 'Cambiado',
        'is_active' => false,
        'notes' => 'legacy_sku: NO',
    ]);

    $result = $service->import(sampleCatalog());
    $material = Material::query()->where('code', 'MAT-0001')->firstOrFail();

    expect($result['inserted'])->toBe(0)
        ->and($result['updated'])->toBe(10)
        ->and($result['total'])->toBe(10)
        ->and(Material::query()->count())->toBe(10)
        ->and($material->name)->toBe('Structural steel beam')
        ->and($material->is_active)->toBeFalse()
        ->and($material->notes)->toBe('legacy_sku: NO')
        ->and(Activity::query()->where('log_name', 'materials')->count())->toBe(1);
});

it('skips a row without a name', function (): void {
    $path = writeCatalog("code,name,category,unit\nMAT-X,,Steel & Metal,kg\n");

    expect(app(MaterialImportService::class)->import($path)['skipped'])->toBe(1)
        ->and(Material::query()->count())->toBe(0);
});

it('skips a unit longer than 20 characters instead of truncating it', function (): void {
    $warned = false;
    $summary = null;
    Log::listen(function ($message) use (&$warned, &$summary): void {
        $warned = $warned || ($message->level === 'warning' && ($message->context['field'] ?? null) === 'unit');
        if ($message->level === 'warning' && str_contains($message->message, 'skipped for exceeding the length limit')) {
            $summary = $message->context;
        }
    });
    $path = writeCatalog("code,name,category,unit\nMAT-LONG,Viga,Steel & Metal,1234567890123456789012345\n");
    $result = app(MaterialImportService::class)->import($path);

    expect($result)->toMatchArray(['inserted' => 0, 'skipped' => 1])
        ->and(Material::query()->count())->toBe(0)
        ->and($warned)->toBeTrue()
        ->and($summary)->toBe(['codes' => ['MAT-LONG']]);
});

it('does not persist a dry run', function (): void {
    Material::factory()->create(['code' => 'MAT-0001', 'name' => 'Viejo', 'notes' => 'NY']);

    $result = app(MaterialImportService::class)->import(sampleCatalog(), dryRun: true);

    expect($result)->toMatchArray(['inserted' => 9, 'updated' => 1, 'skipped' => 0, 'total' => 10])
        ->and(Material::query()->count())->toBe(1)
        ->and(Material::query()->where('code', 'MAT-0001')->value('name'))->toBe('Viejo')
        ->and(Material::query()->where('code', 'MAT-0001')->value('notes'))->toBe('NY');
});

it('deletes the catalog before importing when truncate is explicit', function (): void {
    Material::factory()->create(['code' => 'EXTRA']);

    $result = app(MaterialImportService::class)->import(sampleCatalog(), truncate: true);

    expect($result['inserted'])->toBe(10)
        ->and(Material::query()->where('code', 'EXTRA')->exists())->toBeFalse()
        ->and(Material::query()->count())->toBe(10);
});

it('rolls back truncate when a material list still references the catalog', function (): void {
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

    expect(fn () => app(MaterialImportService::class)->import(sampleCatalog(), truncate: true))
        ->toThrow(QueryException::class)
        ->and(Material::query()->where('code', 'KEEP')->exists())->toBeTrue()
        ->and(MaterialListItem::query()->count())->toBe(1);
});

it('rejects a header without unit and a missing file', function (): void {
    $path = writeCatalog("code,name,category\nMAT-1,Arena,Aggregates & Earth\n");

    expect(fn () => app(MaterialImportService::class)->import($path))
        ->toThrow(InvalidArgumentException::class, 'Columns found: code, name, category');

    expect(fn () => app(MaterialImportService::class)->import(storage_path('app/no-existe-cwicr.csv')))
        ->toThrow(InvalidArgumentException::class, MaterialImportService::DOWNLOAD_URL);
});

it('builds a stable code and defaults an empty category and unit', function (): void {
    $path = writeCatalog("code,name,category,unit\n,Arena,,\n");
    app(MaterialImportService::class)->import($path);

    $material = Material::query()->sole();
    expect($material->code)->toBe('MAT-'.substr(sha1('Arena|pcs|General'), 0, 10))
        ->and($material->unit)->toBe('pcs')
        ->and($material->category)->toBe('General')
        ->and($material->is_active)->toBeTrue();
});

it('updates a repeated code in the same file without violating the unique index', function (): void {
    $path = writeCatalog("code,name,category,unit\nMAT-1,Arena,Aggregates & Earth,m3\nMAT-1,Arena lavada,Aggregates & Earth,m3\n");
    $result = app(MaterialImportService::class)->import($path);

    expect($result)->toMatchArray(['inserted' => 1, 'updated' => 1, 'total' => 2])
        ->and(Material::query()->sole()->name)->toBe('Arena lavada');
});

function sampleCatalog(): string
{
    return database_path('data/cwicr_materials_sample.csv');
}

function writeCatalog(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'cwicr');
    file_put_contents($path, $contents);

    return $path;
}
