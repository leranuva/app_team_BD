<?php

use App\Enums\LogisticsRole;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->carpenter = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $this->helper = User::factory()->create()->assignRole(LogisticsRole::Helper->value);
});

it('returns the catalog to administrators and supervisors', function (): void {
    $steel = Material::factory()->create([
        'code' => 'MAT-STEEL',
        'name' => 'Rebar',
        'unit' => 'kg',
        'category' => 'Steel & Metal',
        'is_active' => true,
        'notes' => 'No sale en la API',
    ]);
    Material::factory()->inactive()->create([
        'code' => 'MAT-PAINT',
        'name' => 'Rebar paint',
        'category' => 'Paint & Coatings',
        'unit' => 'gal',
    ]);

    foreach ([$this->admin, $this->supervisor] as $user) {
        $this->actingAs($user)
            ->getJson('/api/materials?active=1&search=Rebar&category=Steel%20%26%20Metal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $steel->id)
            ->assertJsonPath('data.0.code', 'MAT-STEEL')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.notes', 'No sale en la API')
            ->assertJsonPath('data.0.list_items_count', 0);
    }

    $this->actingAs($this->admin)->getJson('/api/materials')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->admin)->getJson('/api/materials?active=all')->assertOk()->assertJsonCount(2, 'data');
    $this->actingAs($this->admin)->getJson('/api/materials?active=0')->assertOk()
        ->assertJsonPath('data.0.code', 'MAT-PAINT')
        ->assertJsonPath('data.0.notes', null);
    $this->actingAs($this->admin)->getJson('/api/materials/'.$steel->id)
        ->assertOk()
        ->assertJsonPath('data.notes', 'No sale en la API');
    $this->actingAs($this->admin)->getJson('/api/materials/999999')->assertNotFound();
    $this->actingAs($this->carpenter)->getJson('/api/materials/'.$steel->id)->assertForbidden();

    $this->actingAs($this->admin)->getJson('/api/materials/categories')
        ->assertOk()
        ->assertJsonPath('data', [[
            'value' => 'Steel & Metal',
            'label' => 'Steel & Metal',
            'count' => 1,
        ]]);
    $this->actingAs($this->supervisor)->getJson('/api/materials/units')
        ->assertOk()
        ->assertJsonPath('data', [[
            'value' => 'kg',
            'label' => 'kg',
            'count' => 1,
        ]]);
});

it('rejects a per_page outside 1 to 100', function (): void {
    $this->actingAs($this->admin)->getJson('/api/materials?per_page=101')->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
    $this->actingAs($this->admin)->getJson('/api/materials?per_page=0')->assertUnprocessable();
    $this->actingAs($this->admin)->getJson('/api/materials?active=todos')->assertUnprocessable()
        ->assertJsonValidationErrors('active');
});

it('forbids carpenters and helpers', function (): void {
    foreach ([$this->carpenter, $this->helper] as $user) {
        $this->actingAs($user)->getJson('/api/materials')->assertForbidden();
        $this->actingAs($user)->getJson('/api/materials/categories')->assertForbidden();
        $this->actingAs($user)->getJson('/api/materials/units')->assertForbidden();
    }
});

it('paginates twenty materials per page', function (): void {
    Material::factory()->count(21)->create();

    $this->actingAs($this->admin)->getJson('/api/materials?page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 21)
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.last_page', 2);
});
