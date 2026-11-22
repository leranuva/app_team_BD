<?php

use App\Enums\LogisticsRole;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->material = Material::factory()->create();
});

it('lets administrators manage the catalog and supervisors only read it', function (): void {
    foreach ([LogisticsRole::SuperAdmin, LogisticsRole::Administrator] as $role) {
        $user = User::factory()->create()->assignRole($role->value);

        expect($user->can('viewAny', Material::class))->toBeTrue()
            ->and($user->can('view', $this->material))->toBeTrue()
            ->and($user->can('create', Material::class))->toBeTrue()
            ->and($user->can('update', $this->material))->toBeTrue()
            ->and($user->can('delete', $this->material))->toBeTrue()
            ->and($user->can('import', Material::class))->toBeTrue();
    }

    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);

    expect($supervisor->can('viewAny', Material::class))->toBeTrue()
        ->and($supervisor->can('view', $this->material))->toBeTrue()
        ->and($supervisor->can('create', Material::class))->toBeFalse()
        ->and($supervisor->can('update', $this->material))->toBeFalse()
        ->and($supervisor->can('delete', $this->material))->toBeFalse()
        ->and($supervisor->can('import', Material::class))->toBeFalse();
});

it('hides the catalog from carpenters and helpers', function (): void {
    foreach ([LogisticsRole::Carpenter, LogisticsRole::Helper] as $role) {
        $user = User::factory()->create()->assignRole($role->value);

        expect($user->can('viewAny', Material::class))->toBeFalse()
            ->and($user->can('view', $this->material))->toBeFalse()
            ->and($user->can('create', Material::class))->toBeFalse()
            ->and($user->can('update', $this->material))->toBeFalse()
            ->and($user->can('delete', $this->material))->toBeFalse()
            ->and($user->can('import', Material::class))->toBeFalse();
    }
});

it('refuses to delete a material that a list still references', function (): void {
    $admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $list = MaterialList::query()->create([
        'job_site_id' => JobSite::factory()->create()->id,
        'name' => 'Lista',
        'status' => 'pending',
    ]);
    MaterialListItem::query()->create([
        'material_list_id' => $list->id,
        'material_id' => $this->material->id,
        'budgeted_quantity' => 1,
    ]);

    expect($admin->can('delete', $this->material))->toBeFalse()
        ->and($admin->can('update', $this->material))->toBeTrue();
});

it('refuses an inactive administrator', function (): void {
    $admin = User::factory()->create(['is_active' => false])->assignRole(LogisticsRole::Administrator->value);

    expect($admin->can('viewAny', Material::class))->toBeFalse()
        ->and($admin->can('create', Material::class))->toBeFalse()
        ->and($admin->can('import', Material::class))->toBeFalse();
});
