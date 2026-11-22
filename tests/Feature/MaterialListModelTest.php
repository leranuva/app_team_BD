<?php

use App\Models\MaterialList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rolls the material list columns back and forward', function (): void {
    $migration = require database_path('migrations/2026_11_21_270000_extend_material_lists.php');
    $migration->down();

    expect(Schema::hasColumn('material_lists', 'code'))->toBeFalse()
        ->and(Schema::hasColumn('material_list_items', 'requested_quantity'))->toBeFalse()
        ->and(Schema::hasTable('material_list_counters'))->toBeFalse()
        ->and(Schema::hasColumn('material_lists', 'name'))->toBeTrue()
        ->and(Schema::hasColumn('material_list_items', 'budgeted_quantity'))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumn('material_lists', 'code'))->toBeTrue()
        ->and(Schema::hasColumn('material_lists', 'title'))->toBeTrue()
        ->and(Schema::hasColumn('material_list_items', 'consumed_quantity'))->toBeTrue()
        ->and(Schema::hasColumn('material_list_items', 'unit_cost_cents'))->toBeTrue()
        ->and(Schema::hasTable('material_list_counters'))->toBeTrue();

    expect(MaterialList::factory()->create()->exists)->toBeTrue();
});
