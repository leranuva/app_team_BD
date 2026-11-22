<?php

use App\Enums\ChangeOrderStatus;
use App\Enums\LogisticsRole;
use App\Exceptions\InvalidChangeOrderTransitionException;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use App\Notifications\ChangeOrderApprovedNotification;
use App\Notifications\ChangeOrderRejectedNotification;
use App\Notifications\ChangeOrderReviewedNotification;
use App\Notifications\ChangeOrderSubmittedNotification;
use App\Services\ChangeOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    Notification::fake();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->service = app(ChangeOrderService::class);
});

function orderPayload(int $amount = 125000): array
{
    return [
        'title' => 'Muro adicional',
        'description' => 'Cambio de alcance',
        'amount_cents' => $amount,
        'time_impact_days' => 3,
    ];
}

it('issues distinct codes and applies the approved impact once', function (): void {
    $first = $this->service->create($this->supervisor, $this->site, $this->scope, orderPayload());
    $second = $this->service->create($this->supervisor, $this->site, null, orderPayload(-4000));
    $year = now(config('app.business_timezone'))->year;

    expect($first->code)->toBe("CO-{$this->site->id}-{$year}-0001")
        ->and($second->code)->toBe("CO-{$this->site->id}-{$year}-0002")
        ->and($first->status)->toBe(ChangeOrderStatus::Draft);

    $this->service->submit($first, $this->supervisor);
    $this->service->startReview($first, $this->admin);
    $approved = $this->service->approve($first, $this->admin, 'Conforme');
    $this->service->recalculateImpact($this->site);

    expect($approved->status)->toBe(ChangeOrderStatus::Approved)
        ->and($approved->applied_to_scope_at)->not->toBeNull()
        ->and($this->scope->refresh()->approved_change_orders_cents)->toBe(125000)
        ->and($this->scope->approved_time_impact_days)->toBe(3)
        ->and($this->site->refresh()->approved_change_orders_cents)->toBe(0);

    $siteOrder = $this->service->submit($second, $this->supervisor);
    $this->service->startReview($siteOrder, $this->supervisor);
    $this->service->approve($siteOrder, $this->supervisor);

    expect($this->site->refresh()->approved_change_orders_cents)->toBe(-4000)
        ->and($this->scope->refresh()->approved_change_orders_cents)->toBe(125000);

    Notification::assertSentTo($this->admin, ChangeOrderSubmittedNotification::class);
    Notification::assertSentTo($this->supervisor, ChangeOrderReviewedNotification::class);
    Notification::assertSentTo($this->supervisor, ChangeOrderApprovedNotification::class);
});

it('rejects with a reason and refuses an invalid transition', function (): void {
    $order = $this->service->create($this->supervisor, $this->site, $this->scope, orderPayload());
    $this->service->submit($order, $this->supervisor);
    $this->service->startReview($order, $this->supervisor);
    $rejected = $this->service->reject($order, $this->supervisor, 'Fuera de presupuesto');

    expect($rejected->status)->toBe(ChangeOrderStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Fuera de presupuesto')
        ->and($this->scope->refresh()->approved_change_orders_cents)->toBe(0);
    Notification::assertSentTo($this->supervisor, ChangeOrderRejectedNotification::class);

    expect(fn () => $this->service->cancel($rejected, $this->admin, 'Tarde'))
        ->toThrow(InvalidChangeOrderTransitionException::class);
});

it('cancels an open order without changing the cache', function (): void {
    $order = $this->service->create($this->supervisor, $this->site, $this->scope, orderPayload());
    $cancelled = $this->service->cancel($order, $this->admin, 'Duplicada');

    expect($cancelled->status)->toBe(ChangeOrderStatus::Cancelled)
        ->and($cancelled->cancelled_reason)->toBe('Duplicada')
        ->and($this->scope->refresh()->approved_time_impact_days)->toBe(0);
});
