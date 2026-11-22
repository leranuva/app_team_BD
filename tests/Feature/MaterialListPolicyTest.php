<?php

use App\Enums\LogisticsRole;
use App\Enums\MaterialListStatus;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->owner = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->owner->jobSites()->attach($this->site);
    $this->list = MaterialList::factory()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'requested_by' => $this->owner->id,
        'status' => MaterialListStatus::Draft,
    ]);
    MaterialListItem::factory()->create(['material_list_id' => $this->list->id, 'unit_cost_cents' => 100]);
});

it('checks the catalog of list abilities', function (string $role, bool $member, string $ability, bool $allowed): void {
    $user = $role === 'owner'
        ? $this->owner
        : User::factory()->create()->assignRole($role);
    if ($member && $role !== 'owner') {
        $user->jobSites()->attach($this->site);
    }

    expect($user->can($ability, $ability === 'create' || $ability === 'viewAny' ? MaterialList::class : $this->list))->toBe($allowed);
})->with([
    'admin views' => [LogisticsRole::Administrator->value, false, 'view', true],
    'admin creates' => [LogisticsRole::Administrator->value, false, 'create', true],
    'site supervisor views' => [LogisticsRole::Supervisor->value, true, 'view', true],
    'outside supervisor cannot view' => [LogisticsRole::Supervisor->value, false, 'view', false],
    'owner updates a draft' => ['owner', true, 'update', true],
    'carpenter cannot view' => [LogisticsRole::Carpenter->value, true, 'viewAny', false],
    'helper cannot view a list' => [LogisticsRole::Helper->value, true, 'view', false],
    'owner submits' => ['owner', true, 'submit', true],
    'nobody deletes' => [LogisticsRole::Administrator->value, false, 'delete', false],
    'admin force deletes' => [LogisticsRole::Administrator->value, false, 'forceDelete', true],
    'super admin views any' => [LogisticsRole::SuperAdmin->value, false, 'viewAny', true],
]);

it('refuses an inactive administrator', function (): void {
    $admin = User::factory()->create(['is_active' => false])->assignRole(LogisticsRole::Administrator->value);

    expect($admin->can('viewAny', MaterialList::class))->toBeFalse()
        ->and($admin->can('create', MaterialList::class))->toBeFalse();
});

it('opens delivery only after the list is ordered', function (): void {
    expect($this->owner->can('registerDelivery', $this->list))->toBeFalse();
    $this->list->update(['status' => MaterialListStatus::Ordered]);

    expect($this->owner->fresh()->can('registerDelivery', $this->list->fresh()))->toBeTrue()
        ->and($this->owner->can('registerConsumption', $this->list->fresh()))->toBeFalse();
});
