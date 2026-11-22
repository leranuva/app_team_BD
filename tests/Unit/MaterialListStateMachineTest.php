<?php

use App\Enums\LogisticsRole;
use App\Enums\MaterialListStatus;
use App\Exceptions\InvalidMaterialListTransitionException;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\User;
use App\Services\MaterialListStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->machine = app(MaterialListStateMachine::class);
});

it('allows and denies each transition by role and amount', function (MaterialListStatus $from, MaterialListStatus $to, string $role, ?int $cost, bool $member, bool $allowed): void {
    $actor = User::factory()->create()->assignRole($role);
    if ($member) {
        $actor->jobSites()->attach($this->site);
    }
    $list = MaterialList::factory()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'requested_by' => $actor->id,
        'status' => $from,
    ]);
    MaterialListItem::factory()->create([
        'material_list_id' => $list->id,
        'requested_quantity' => 1,
        'unit_cost_cents' => $cost,
    ]);

    expect($this->machine->actorMay($from, $to, $actor->fresh(), $list))->toBe($allowed);
})->with([
    'requester submits' => [MaterialListStatus::Draft, MaterialListStatus::Submitted, LogisticsRole::Supervisor->value, 100, true, true],
    'admin submits another list' => [MaterialListStatus::Draft, MaterialListStatus::Submitted, LogisticsRole::Administrator->value, 100, false, true],
    'the author can submit' => [MaterialListStatus::Draft, MaterialListStatus::Submitted, LogisticsRole::Carpenter->value, 100, true, true],
    'admin approves any amount' => [MaterialListStatus::Submitted, MaterialListStatus::Approved, LogisticsRole::Administrator->value, 900000, false, true],
    'supervisor approves within threshold' => [MaterialListStatus::Submitted, MaterialListStatus::Approved, LogisticsRole::Supervisor->value, 250000, true, true],
    'supervisor cannot approve above threshold' => [MaterialListStatus::Submitted, MaterialListStatus::Approved, LogisticsRole::Supervisor->value, 250001, true, false],
    'supervisor cannot approve without a cost' => [MaterialListStatus::Submitted, MaterialListStatus::Approved, LogisticsRole::Supervisor->value, null, true, false],
    'outside supervisor cannot approve' => [MaterialListStatus::Submitted, MaterialListStatus::Approved, LogisticsRole::Supervisor->value, 100, false, false],
    'requester cancels a draft' => [MaterialListStatus::Draft, MaterialListStatus::Cancelled, LogisticsRole::Supervisor->value, 100, true, true],
    'admin cancels an ordered list' => [MaterialListStatus::Ordered, MaterialListStatus::Cancelled, LogisticsRole::Administrator->value, 100, false, true],
    'supervisor cannot cancel an ordered list' => [MaterialListStatus::Ordered, MaterialListStatus::Cancelled, LogisticsRole::Supervisor->value, 100, true, false],
    'supervisor marks ordered' => [MaterialListStatus::Approved, MaterialListStatus::Ordered, LogisticsRole::Supervisor->value, 100, true, true],
    'consumed has no exit' => [MaterialListStatus::Consumed, MaterialListStatus::Cancelled, LogisticsRole::Administrator->value, 100, false, false],
]);

it('renders an invalid transition as 422', function (): void {
    $exception = new InvalidMaterialListTransitionException('consumed', 'draft', []);
    $response = $exception->render(request());

    expect($response->status())->toBe(422)
        ->and($response->getData(true))->toMatchArray(['from' => 'consumed', 'to' => 'draft', 'allowed' => []]);
});
