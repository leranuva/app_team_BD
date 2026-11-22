<?php

use App\Enums\ChangeOrderStatus;
use App\Enums\LogisticsRole;
use App\Exceptions\InvalidChangeOrderTransitionException;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use App\Services\ChangeOrderStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function orderActor(string $role, JobSite $site, bool $member = true): User
{
    $user = User::factory()->create()->assignRole($role);
    if ($member) {
        $user->jobSites()->attach($site);
    }

    return $user;
}

beforeEach(function (): void {
    $this->seed();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->machine = app(ChangeOrderStateMachine::class);
});

it('allows and denies each transition by role and amount', function (ChangeOrderStatus $from, ChangeOrderStatus $to, string $role, int $amount, bool $member, bool $allowed): void {
    $actor = orderActor($role, $this->site, $member);
    $order = ChangeOrder::factory()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'created_by' => $actor->id,
        'requested_by' => $actor->id,
        'status' => $from,
        'amount_cents' => $amount,
    ]);

    expect($this->machine->actorMay($from, $to, $actor, $order))->toBe($allowed);
})->with([
    'requester submits' => [ChangeOrderStatus::Draft, ChangeOrderStatus::Submitted, LogisticsRole::Supervisor->value, 100, true, true],
    'requester without a management role can still be the author' => [ChangeOrderStatus::Draft, ChangeOrderStatus::Submitted, LogisticsRole::Carpenter->value, 100, true, true],
    'admin reviews' => [ChangeOrderStatus::Submitted, ChangeOrderStatus::UnderReview, LogisticsRole::Administrator->value, 100, false, true],
    'outside supervisor cannot review' => [ChangeOrderStatus::Submitted, ChangeOrderStatus::UnderReview, LogisticsRole::Supervisor->value, 100, false, false],
    'site supervisor reviews' => [ChangeOrderStatus::Submitted, ChangeOrderStatus::UnderReview, LogisticsRole::Supervisor->value, 100, true, true],
    'supervisor approves within threshold' => [ChangeOrderStatus::UnderReview, ChangeOrderStatus::Approved, LogisticsRole::Supervisor->value, 500000, true, true],
    'supervisor cannot approve above threshold' => [ChangeOrderStatus::UnderReview, ChangeOrderStatus::Approved, LogisticsRole::Supervisor->value, 600000, true, false],
    'supervisor cannot approve a large credit' => [ChangeOrderStatus::UnderReview, ChangeOrderStatus::Approved, LogisticsRole::Supervisor->value, -600000, true, false],
    'admin approves any amount' => [ChangeOrderStatus::UnderReview, ChangeOrderStatus::Approved, LogisticsRole::Administrator->value, 900000, false, true],
    'supervisor rejects' => [ChangeOrderStatus::UnderReview, ChangeOrderStatus::Rejected, LogisticsRole::Supervisor->value, 900000, true, true],
    'admin cancels a draft' => [ChangeOrderStatus::Draft, ChangeOrderStatus::Cancelled, LogisticsRole::Administrator->value, 100, false, true],
    'supervisor cannot cancel' => [ChangeOrderStatus::Draft, ChangeOrderStatus::Cancelled, LogisticsRole::Supervisor->value, 100, true, false],
    'approved cannot be cancelled' => [ChangeOrderStatus::Approved, ChangeOrderStatus::Cancelled, LogisticsRole::Administrator->value, 100, false, false],
]);

it('lists the allowed edges and renders an invalid transition as 422', function (): void {
    expect(array_map(fn (ChangeOrderStatus $status) => $status->value, $this->machine->allowed(ChangeOrderStatus::UnderReview)))
        ->toBe(['approved', 'rejected', 'cancelled']);

    try {
        $this->machine->assertAvailable(ChangeOrderStatus::Approved, ChangeOrderStatus::Cancelled);
        $this->fail('The transition should be rejected.');
    } catch (InvalidChangeOrderTransitionException $exception) {
        $response = $exception->render(request());
        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['allowed'])->toBe([])
            ->and($response->getData(true)['from'])->toBe('approved')
            ->and($response->getData(true)['to'])->toBe('cancelled');
    }
});
