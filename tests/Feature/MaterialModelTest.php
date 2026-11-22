<?php

use App\Models\JobSite;
use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rolls the materials catalog back to the legacy columns and forward again', function (): void {
    $migration = require database_path('migrations/2026_11_21_260000_reshape_materials_catalog.php');
    $migration->down();

    expect(Schema::hasColumn('materials', 'sku'))->toBeTrue()
        ->and(Schema::hasColumn('materials', 'unit_cost'))->toBeTrue()
        ->and(Schema::hasColumn('materials', 'stock'))->toBeTrue()
        ->and(Schema::hasColumn('materials', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('materials', 'code'))->toBeFalse()
        ->and(Schema::hasColumn('materials', 'category'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('materials', 'code'))->toBeTrue()
        ->and(Schema::hasColumn('materials', 'category'))->toBeTrue()
        ->and(Schema::hasColumn('materials', 'is_active'))->toBeTrue()
        ->and(Schema::hasColumn('materials', 'notes'))->toBeTrue()
        ->and(Schema::hasColumn('materials', 'sku'))->toBeFalse()
        ->and(Schema::hasColumn('materials', 'unit_cost'))->toBeFalse()
        ->and(Schema::hasIndex('materials', ['code']))->toBeTrue()
        ->and(Schema::hasIndex('materials', ['name']))->toBeTrue()
        ->and(Schema::hasIndex('materials', ['category', 'is_active']))->toBeTrue();

    expect(Material::factory()->create()->exists)->toBeTrue();
});

it('filters the catalog and labels a material with its unit', function (): void {
    $steel = Material::factory()->create([
        'code' => 'MAT-STEEL01',
        'name' => 'Rebar',
        'unit' => 'kg',
        'category' => 'Steel & Metal',
    ]);
    Material::factory()->create([
        'code' => 'MAT-PAINT01',
        'name' => 'Rebar paint',
        'unit' => 'kg',
        'category' => 'Paint & Coatings',
        'is_active' => false,
    ]);

    expect(Material::active()->pluck('id')->all())->toBe([$steel->id])
        ->and(Material::byCategory('Steel & Metal')->pluck('code')->all())->toBe(['MAT-STEEL01'])
        ->and(Material::search('MAT-STEEL')->pluck('id')->all())->toBe([$steel->id])
        ->and(Material::search('Rebar')->pluck('code')->all())->toContain('MAT-STEEL01')
        ->and(Material::byCategory('Steel & Metal')->search('paint')->count())->toBe(0)
        ->and($steel->displayLabel())->toBe('Rebar (kg)')
        ->and($steel->is_active)->toBeTrue();
});

it('audits a manual edit of catalog fields and ignores notes and silenced updates', function (): void {
    $material = Material::factory()->create(['name' => 'Rebar', 'unit' => 'kg']);
    expect(Activity::forSubject($material)->count())->toBe(0);

    $material->update(['name' => 'Rebar epoxy', 'notes' => 'ASTM A615']);

    $audit = Activity::forSubject($material)->sole();
    expect($audit->log_name)->toBe('materials')
        ->and($audit->description)->toBe('material.updated')
        ->and($audit->event)->toBe('updated')
        ->and($audit->getExtraProperty('attributes.name'))->toBe('Rebar epoxy')
        ->and($audit->getExtraProperty('old.name'))->toBe('Rebar')
        ->and($audit->getExtraProperty('attributes.notes'))->toBeNull();

    $material->update(['notes' => 'Solo nota de NY']);
    expect(Activity::forSubject($material)->count())->toBe(1);

    Material::withoutManualAudit(fn () => $material->update(['unit' => 'm']));
    expect($material->fresh()->unit)->toBe('m')
        ->and(Activity::forSubject($material)->count())->toBe(1);
});

it('writes no activity rows when ten updates run inside withoutManualAudit', function (): void {
    $materials = Material::factory()->count(10)->create();
    $flagDuring = null;

    Material::withoutManualAudit(function () use ($materials, &$flagDuring): void {
        $flagDuring = Material::auditsManualEdits();
        foreach ($materials as $material) {
            $material->update(['name' => $material->name.' importado', 'category' => 'General']);
        }
    });

    expect($flagDuring)->toBeFalse()
        ->and(Material::auditsManualEdits())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'materials')->count())->toBe(0)
        ->and(Material::query()->where('name', 'like', '%importado')->count())->toBe(10);

    try {
        Material::withoutManualAudit(function (): never {
            throw new RuntimeException('importación interrumpida');
        });
    } catch (RuntimeException) {
    }

    expect(Material::auditsManualEdits())->toBeTrue();

    $materials->first()->update(['unit' => 'm']);
    expect(Activity::query()->where('log_name', 'materials')->count())->toBe(1);
});

it('keeps the legacy sku on the same id and on its list item', function (): void {
    $migration = require database_path('migrations/2026_11_21_260000_reshape_materials_catalog.php');
    $migration->down();

    $now = now();
    $siteId = JobSite::factory()->create()->id;
    $keptId = DB::table('materials')->insertGetId([
        'sku' => 'SKU-LEGACY-1',
        'name' => 'Clavo',
        'unit' => 'pcs',
        'unit_cost' => 1.25,
        'stock' => 4,
        'minimum_stock' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('materials')->insert([
        'sku' => '',
        'name' => 'Sin codigo',
        'unit' => 'm',
        'unit_cost' => 0,
        'stock' => 0,
        'minimum_stock' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $listId = DB::table('material_lists')->insertGetId([
        'job_site_id' => $siteId,
        'name' => 'Lista vieja',
        'status' => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('material_list_items')->insert([
        'material_list_id' => $listId,
        'material_id' => $keptId,
        'budgeted_quantity' => 2,
        'used_quantity' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration->up();

    $kept = Material::query()->findOrFail($keptId);
    $generated = Material::query()->where('name', 'Sin codigo')->sole();

    expect($kept->code)->toBe('SKU-LEGACY-1')
        ->and($kept->notes)->toBe('legacy_sku: SKU-LEGACY-1')
        ->and(DB::table('material_list_items')->where('material_id', $keptId)->value('budgeted_quantity'))->toEqual(2)
        ->and($generated->code)->toBe('MAT-'.substr(sha1('Sin codigo|m|General'), 0, 10))
        ->and($generated->notes)->toBeNull()
        ->and(config('materials.unit_max_length'))->toBe(20)
        ->and(config('materials.categories'))->toContain('Steel & Metal', 'Pipes & Fittings', 'General');
});
