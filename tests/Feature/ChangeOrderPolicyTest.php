<?php

use App\Enums\LogisticsRole;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->worker = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $this->worker->jobSites()->attach($this->site);
    $this->draft = ChangeOrder::factory()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'created_by' => $this->supervisor->id,
        'requested_by' => $this->supervisor->id,
        'amount_cents' => 1000,
    ]);
});

it('lets the site supervisor see and update only their draft', function (): void {
    expect($this->supervisor->can('viewAny', ChangeOrder::class))->toBeTrue()
        ->and($this->supervisor->can('view', $this->draft))->toBeTrue()
        ->and($this->supervisor->can('update', $this->draft))->toBeTrue()
        ->and($this->supervisor->can('submit', $this->draft))->toBeTrue()
        ->and($this->supervisor->can('delete', $this->draft))->toBeFalse();
});

it('hides orders from a worker', function (): void {
    expect($this->worker->can('viewAny', ChangeOrder::class))->toBeFalse()
        ->and($this->worker->can('view', $this->draft))->toBeFalse()
        ->and($this->worker->can('create', [ChangeOrder::class, $this->site]))->toBeFalse();
});

it('lets an administrator review and cancel, and blocks approval of a finished order', function (): void {
    $submitted = ChangeOrder::factory()->submitted()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'requested_by' => $this->supervisor->id,
    ]);
    $review = ChangeOrder::factory()->underReview()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'amount_cents' => 600000,
    ]);

    expect($this->admin->can('startReview', $submitted))->toBeTrue()
        ->and($this->supervisor->can('startReview', $submitted))->toBeTrue()
        ->and($this->admin->can('approve', $review))->toBeTrue()
        ->and($this->supervisor->can('approve', $review))->toBeFalse()
        ->and($this->supervisor->can('reject', $review))->toBeTrue()
        ->and($this->admin->can('cancel', $this->draft))->toBeTrue()
        ->and($this->supervisor->can('cancel', $this->draft))->toBeFalse()
        ->and($this->admin->can('forceDelete', $this->draft))->toBeTrue();
});

it('lets the site supervisor approve at the threshold', function (): void {
    $review = ChangeOrder::factory()->underReview()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'amount_cents' => 500000,
    ]);

    expect($this->supervisor->can('approve', $review))->toBeTrue()
        ->and($this->admin->can('create', ChangeOrder::class))->toBeTrue();
});
