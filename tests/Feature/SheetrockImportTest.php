<?php

use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('imports the nyc sheetrock catalog as the drywall category', function (): void {
    $this->artisan('materials:import-sheetrock')
        ->assertSuccessful()
        ->expectsOutputToContain('[OK] Imported: 31 · Updated: 0 · Skipped: 0 · Total: 31');

    $panel = Material::query()->where('code', 'SR-FIR-001')->first();

    foreach (Material::all() as $material) {
        expect($material->referencePhotoUrl())->not->toBeNull();
        expect(public_path(ltrim(parse_url($material->referencePhotoUrl(), PHP_URL_PATH), '/')))->toBeFile();
    }

    expect(Material::query()->count())->toBe(31)
        ->and(Material::query()->where('category', 'Drywall')->count())->toBe(31)
        ->and(Material::query()->where('name', 'not like', 'USG Sheetrock %')->count())->toBe(0)
        ->and(config('materials.categories'))->toContain('Drywall')
        ->and($panel->unit)->toBe('Sheet')
        ->and($panel->name)->toContain('Firecode X')
        ->and($panel->notes)->toContain('Fire-Rated (Type X & C)')
        ->and($panel->notes)->toContain('UL Type SCX')
        ->and($panel->notes)->not->toContain('17.48');
});

it('updates an existing sheetrock code without writing an activity row', function (): void {
    Material::factory()->create(['code' => 'SR-STD-001', 'category' => 'General', 'name' => 'Viejo']);

    $this->artisan('materials:import-sheetrock')
        ->assertSuccessful()
        ->expectsOutputToContain('Updated: 1');

    expect(Material::query()->where('code', 'SR-STD-001')->value('category'))->toBe('Drywall')
        ->and(Activity::query()->where('log_name', 'materials')->count())->toBe(0);
});
