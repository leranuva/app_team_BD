<?php

use App\Enums\LogisticsRole;
use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftReassignedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->site = JobSite::factory()->create();
    $this->shift = Shift::factory()->for($this->site, 'jobSite')->create([
        'starts_at' => '2026-11-21 13:00:00',
        'ends_at' => '2026-11-21 17:00:00',
    ]);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
});

it('returns calendar catalogs scoped to the authenticated user', function (): void {
    $url = '/api/shifts/options';
    $outside = JobSite::factory()->create();
    $deleted = JobSite::factory()->create();
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $supervisor->jobSites()->attach([$this->site->id, $deleted->id]);
    $deleted->delete();

    $this->getJson($url)->assertForbidden();
    $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
    $this->actingAs($supervisor)->getJson($url)->assertOk()->assertExactJson([
        'job_sites' => [['id' => $this->site->id, 'name' => $this->site->name]],
        'statuses' => array_map(fn (ShiftStatus $status) => ['value' => $status->value, 'label' => $status->label()], ShiftStatus::cases()),
        'roles' => array_map(fn (ShiftRole $role) => ['value' => $role->value, 'label' => $role->label()], ShiftRole::cases()),
        'can_edit' => true,
    ]);
    $this->shift->worker->update(['primary_job_site_id' => $outside->id]);
    $this->actingAs($this->shift->worker)->getJson($url)->assertOk()
        ->assertJsonCount(2, 'job_sites')->assertJsonPath('can_edit', false)
        ->assertJsonFragment(['id' => $this->site->id, 'name' => $this->site->name])
        ->assertJsonFragment(['id' => $outside->id, 'name' => $outside->name]);
    $this->actingAs($this->admin)->getJson($url)->assertOk()->assertJsonCount(2, 'job_sites')
        ->assertJsonFragment(['id' => $outside->id, 'name' => $outside->name]);
});

it('distinguishes calendar editing access for each event', function (): void {
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $supervisor->jobSites()->attach($this->site);
    $cancelled = Shift::factory()->cancelled()->for($this->site, 'jobSite')->for($this->shift->worker, 'worker')
        ->create(['starts_at' => '2026-11-21 18:00:00', 'ends_at' => '2026-11-21 19:00:00']);
    $query = '/api/shifts?from=2026-11-21&to=2026-11-22';

    $this->actingAs($supervisor)->getJson('/api/shifts/options')->assertJsonPath('can_edit', true);
    $this->getJson($query)->assertOk()
        ->assertJsonPath('data.0.id', $this->shift->id)
        ->assertJsonPath('data.0.extendedProps.can_edit', true)
        ->assertJsonPath('data.1.id', $cancelled->id)
        ->assertJsonPath('data.1.extendedProps.can_edit', false);
    $this->actingAs($this->shift->worker)->getJson($query)->assertOk()
        ->assertJsonPath('data.0.extendedProps.can_edit', false);
    $this->shift->worker->forceFill(['is_active' => false])->save();
    $this->actingAs($this->admin)->getJson($query)->assertOk()
        ->assertJsonPath('data.0.extendedProps.can_edit', false);
});

it('provides matching calendar bootstrap options through Inertia', function (): void {
    $url = '/shifts/calendar?status=programmed';
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($this->shift->worker);
    $options = $this->getJson('/api/shifts/options')->assertOk()->json();

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Shifts/Calendar')
        ->where('options', $options)
        ->where('canEdit', false)
        ->where('timeZone', 'America/New_York'));
});

it('limits calendar options to 120 requests without consuming the shift list quota', function (): void {
    $this->actingAs($this->admin);

    foreach (range(1, 120) as $_) {
        $this->getJson('/api/shifts/options')->assertOk();
    }

    $this->getJson('/api/shifts/options')->assertTooManyRequests();
    $this->getJson('/api/shifts?from=2026-11-21&to=2026-11-21')->assertOk();
});

it('returns only authorized shifts with New York offsets in winter and summer', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-09T07:00:00-04:00'));
    $summer = Shift::factory()->for($this->site, 'jobSite')->for($this->shift->worker, 'worker')->create([
        'starts_at' => '2026-03-09 12:00:00',
        'ends_at' => '2026-03-09 16:00:00',
    ]);
    Shift::factory()->for($this->site, 'jobSite')->create();

    $this->getJson('/api/shifts')->assertUnauthorized();
    $this->actingAs($this->shift->worker)->getJson('/api/shifts?from=2026-03-09&to=2026-03-10')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $summer->id)
        ->assertJsonPath('data.0.start', '2026-03-09T08:00:00-04:00');
    $this->getJson('/api/shifts?from=2026-11-21&to=2026-11-22')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.start', '2026-11-21T08:00:00-05:00')
        ->assertJsonPath('data.0.end', '2026-11-21T12:00:00-05:00')
        ->assertJsonPath('data.0.job_site.id', $this->site->id)
        ->assertJsonPath('data.0.worker.role', 'carpintero')
        ->assertJsonPath('data.0.extendedProps.worker_name', $this->shift->worker->name)
        ->assertJsonPath('meta.from', '2026-11-21T00:00:00-05:00')
        ->assertJsonPath('meta.count', 1)
        ->assertJsonMissingPath('data.0.worker.email')
        ->assertJsonMissingPath('data.0.user_id')
        ->assertJsonMissingPath('data.0.updated_by');
});

it('limits supervisors to member sites and rejects forbidden filter targets', function (): void {
    $outside = Shift::factory()->create(['starts_at' => $this->shift->starts_at, 'ends_at' => $this->shift->ends_at]);
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $supervisor->jobSites()->attach($this->site);
    $query = '/api/shifts?from=2026-11-21&to=2026-11-22';

    $this->actingAs($supervisor)->getJson($query)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($query.'&job_site_id='.$outside->job_site_id)->assertForbidden();
    $this->actingAs($this->shift->worker)->getJson($query.'&worker_id='.$outside->user_id)->assertForbidden();
    $this->actingAs($this->admin)->getJson($query)->assertOk()->assertJsonCount(2, 'data');
    $helper = User::factory()->create()->assignRole(LogisticsRole::Helper->value);
    $helper->jobSites()->attach($this->site);
    $this->actingAs($helper)->getJson($query)->assertOk()->assertJsonCount(0, 'data');
    $superAdmin = User::factory()->create()->assignRole(LogisticsRole::SuperAdmin->value);
    $this->actingAs($superAdmin)->getJson($query)->assertOk()->assertJsonCount(2, 'data');
    $this->shift->worker->delete();
    $this->getJson($query.'&worker_id='.$this->shift->user_id)->assertOk()->assertJsonCount(0, 'data');
});

it('rejects malformed, oversized and overfull ranges', function (): void {
    $this->actingAs($this->admin)->getJson('/api/shifts')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    $this->getJson('/api/shifts?from=next%20Monday&to=2026-12-01')->assertUnprocessable()->assertJsonValidationErrors('from');
    $this->getJson('/api/shifts?from=2026-10-01&to=2027-02-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson('/api/shifts?from=2026-11-21&to=2026-11-21')->assertOk()->assertJsonPath('meta.count', 0);

    $row = [
        'job_site_id' => $this->site->id, 'user_id' => $this->shift->user_id,
        'starts_at' => '2026-11-21 13:00:00', 'ends_at' => '2026-11-21 17:00:00',
    ];
    foreach (range(1, 8) as $_) {
        Shift::query()->insert(array_fill(0, 250, $row));
    }

    $this->getJson('/api/shifts?from=2026-11-21&to=2026-11-22')
        ->assertUnprocessable()->assertJsonValidationErrors('to');
});

it('throttles the sixty-first list request', function (): void {
    $this->actingAs($this->admin);
    $query = '/api/shifts?from=2026-11-21&to=2026-11-21';
    foreach (range(1, 60) as $_) {
        $this->getJson($query)->assertOk();
    }

    $this->getJson($query)->assertTooManyRequests();
});

it('filters by local date range, worker, site, role and status', function (): void {
    Shift::factory()->for($this->site, 'jobSite')->create(['starts_at' => $this->shift->starts_at, 'ends_at' => $this->shift->ends_at]);
    Shift::factory()->confirmed()->for($this->site, 'jobSite')->for($this->shift->worker, 'worker')
        ->create(['starts_at' => $this->shift->starts_at, 'ends_at' => $this->shift->ends_at]);
    $this->actingAs($this->admin)->getJson('/api/shifts?'.http_build_query([
        'from' => '2026-11-21', 'to' => '2026-11-22', 'worker_id' => $this->shift->user_id,
        'job_site_id' => $this->site->id, 'status' => 'programmed', 'role_required' => 'carpintero',
    ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->shift->id);
});

it('rejects unauthorized actors and changes only the time with an audit entry', function (): void {
    Notification::fake();
    $url = "/api/shifts/{$this->shift->id}/reassign";
    $payload = [
        'start_at' => '2026-11-21T09:00:00-05:00', 'end_at' => '2026-11-21T13:00:00-05:00',
        'expected_updated_at' => $this->shift->updated_at->toIso8601String(),
    ];
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);

    $this->actingAs($supervisor)->patchJson($url, $payload)->assertForbidden();
    $this->actingAs($this->shift->worker)->patchJson($url, $payload)->assertForbidden();
    $this->actingAs($this->admin)->patchJson($url, $payload + ['job_site_id' => $this->site->id])->assertUnprocessable();
    $this->patchJson($url, $payload)->assertOk()
        ->assertJsonPath('data.start', $payload['start_at'])
        ->assertJsonPath('data.job_site.id', $this->site->id)
        ->assertJsonPath('data.worker.id', $this->shift->user_id)
        ->assertJsonPath('message', 'Shift rescheduled');

    Notification::assertSentTo($this->shift->worker, ShiftReassignedNotification::class,
        fn (ShiftReassignedNotification $notification, array $channels) => $channels === ['database', 'mail']
            && $notification->toDatabase($this->shift->worker)['previous_start'] === '2026-11-21T08:00:00-05:00'
            && $notification->toDatabase($this->shift->worker)['start'] === $payload['start_at']
            && $notification->toDatabase($this->shift->worker)['actor_name'] === $this->admin->name);

    expect($this->shift->fresh()->starts_at->toIso8601String())->toBe('2026-11-21T14:00:00+00:00');
    $activity = Activity::forSubject($this->shift)->where('event', 'updated')->sole();
    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->getExtraProperty('old.starts_at'))->toBe('2026-11-21T13:00:00.000000Z')
        ->and($activity->getExtraProperty('attributes.starts_at'))->toBe('2026-11-21T14:00:00.000000Z');
});

it('returns the committed event when the same version is submitted twice in one second', function (): void {
    Notification::fake();
    $this->travelTo($this->shift->updated_at);
    $url = "/api/shifts/{$this->shift->id}/reassign";
    $query = '/api/shifts?from=2026-11-21&to=2026-11-22';
    $stamp = $this->actingAs($this->admin)->getJson($query)->assertOk()->json('data.0.updated_at');
    expect($stamp)->toMatch('/T\d{2}:\d{2}:\d{2}-0[45]:00$/');
    $first = ['start_at' => '2026-11-21T09:00:00-05:00', 'end_at' => '2026-11-21T13:00:00-05:00'];
    $updated = $this->patchJson($url, $first + ['expected_updated_at' => $stamp])->assertOk()->json('data');

    expect($updated['updated_at'])->not->toBe($stamp);

    $this->patchJson($url, [
        'start_at' => '2026-11-21T10:00:00-05:00', 'end_at' => '2026-11-21T14:00:00-05:00',
        'expected_updated_at' => $stamp,
    ])->assertConflict()->assertJsonPath('current', $updated);

    $this->patchJson($url, $first + ['expected_updated_at' => '2026-11-21T08:00:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('expected_updated_at');
    Notification::assertSentToTimes($this->shift->worker, ShiftReassignedNotification::class, 1);
});

it('does not notify a supervisor changing their own shift', function (): void {
    Notification::fake();
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $supervisor->jobSites()->attach($this->site);
    $this->shift->update(['user_id' => $supervisor->id, 'role_required' => 'supervisor']);

    $this->actingAs($supervisor)->patchJson("/api/shifts/{$this->shift->id}/reassign", [
        'start_at' => '2026-11-21T09:00:00-05:00', 'end_at' => '2026-11-21T13:00:00-05:00',
        'expected_updated_at' => $this->shift->updated_at->toIso8601String(),
    ])->assertOk();

    Notification::assertNothingSent();
});

it('reports overlap under the public field and accepts the exact boundary', function (): void {
    $url = "/api/shifts/{$this->shift->id}/reassign";
    Shift::factory()->for($this->site, 'jobSite')->for($this->shift->worker, 'worker')->create([
        'starts_at' => '2026-11-21 18:00:00', 'ends_at' => '2026-11-21 21:00:00',
    ]);

    $version = ['expected_updated_at' => $this->shift->updated_at->toIso8601String()];
    $this->actingAs($this->admin)->patchJson($url, [
        'start_at' => '2026-11-21T10:00:00-05:00', 'end_at' => '2026-11-21T14:00:00-05:00',
    ] + $version)->assertUnprocessable()->assertJsonValidationErrors('start_at');
    expect($this->shift->fresh()->starts_at->equalTo($this->shift->starts_at))->toBeTrue();
    $this->patchJson($url, [
        'start_at' => '2026-11-21T10:00:00-05:00', 'end_at' => '2026-11-21T13:00:00-05:00',
    ] + $version)->assertOk();
    $this->patchJson($url, [
        'start_at' => '2026-11-21T10:00:00', 'end_at' => '2026-11-21T13:00:00',
    ] + $version)->assertUnprocessable()->assertJsonValidationErrors(['start_at', 'end_at']);
});

it('requires the version and treats an unchanged interval as a no-op', function (): void {
    Notification::fake();
    $url = "/api/shifts/{$this->shift->id}/reassign";
    $payload = ['start_at' => '2026-11-21T08:00:00-05:00', 'end_at' => '2026-11-21T12:00:00-05:00'];
    $this->actingAs($this->admin)->patchJson($url, $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.expected_updated_at.0', 'Include the shift version to avoid overwriting changes');

    $stamp = $this->shift->updated_at->setTimezone('America/New_York')->toIso8601String();
    $this->patchJson($url, [
        'start_at' => $payload['end_at'], 'end_at' => $payload['start_at'], 'expected_updated_at' => $stamp,
    ])->assertUnprocessable()->assertJsonValidationErrors('end_at');
    $this->patchJson($url, $payload + ['expected_updated_at' => $stamp])->assertOk()
        ->assertJsonPath('data.updated_at', $stamp);

    expect(Activity::forSubject($this->shift)->where('event', 'updated')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('serializes event and version offsets across daylight saving time', function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-09T08:00:00-04:00'));
    $shift = Shift::factory()->for($this->site, 'jobSite')->create([
        'starts_at' => '2026-03-09 12:00:00', 'ends_at' => '2026-03-09 16:00:00',
    ]);
    $version = $this->actingAs($this->admin)->getJson('/api/shifts?from=2026-03-09&to=2026-03-10')
        ->assertOk()->assertJsonPath('data.0.updated_at', '2026-03-09T08:00:00-04:00')->json('data.0.updated_at');

    $this->travelTo(CarbonImmutable::parse('2026-11-21T07:00:00-05:00'));
    $this->patchJson("/api/shifts/{$shift->id}/reassign", [
        'start_at' => '2026-11-21T08:00:00-05:00', 'end_at' => '2026-11-21T12:00:00-05:00',
        'expected_updated_at' => $version,
    ])->assertOk()
        ->assertJsonPath('data.start', '2026-11-21T08:00:00-05:00')
        ->assertJsonPath('data.updated_at', '2026-11-21T07:00:00-05:00');
});

it('throttles the 121st reassignment independently of list requests', function (): void {
    $this->actingAs($this->admin);
    $url = "/api/shifts/{$this->shift->id}/reassign";
    foreach (range(1, 120) as $_) {
        $this->patchJson($url)->assertUnprocessable();
    }

    $this->patchJson($url)->assertTooManyRequests();
    $this->getJson('/api/shifts?from=2026-11-21&to=2026-11-21')->assertOk();
});
