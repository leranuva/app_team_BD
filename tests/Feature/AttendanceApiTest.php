<?php

use App\Enums\LogisticsRole;
use App\Models\Attendance;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\AttendanceOutsideGeofenceNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-23T13:00:00Z'));
    $this->shift = Shift::factory()->create(['starts_at' => now(), 'ends_at' => now()->addHours(9)]);
    $this->worker = $this->shift->worker;
    $this->position = ['shift_id' => $this->shift->id, 'lat' => 40.75, 'lng' => -73.9, 'accuracy' => 20, 'timestamp' => now()->toIso8601String()];
});

it('authenticates and records entry and exit without returning raw location', function (): void {
    $this->postJson('/api/attendances/clock-in', $this->position)->assertUnauthorized();
    $this->actingAs($this->worker)->postJson('/api/attendances/clock-in', $this->position)
        ->assertCreated()->assertJsonPath('data.clocked_in_at', '2026-11-23T08:00:00-05:00')
        ->assertJsonMissingPath('data.in_latitude');
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('clock.open.shift_id', $this->shift->id)->missing('clock.open.in_latitude'));

    $this->travelTo(CarbonImmutable::parse('2026-11-23T22:00:00Z'));
    $this->postJson('/api/attendances/clock-out', array_replace($this->position, ['timestamp' => now()->toIso8601String()]))
        ->assertOk()->assertJsonPath('data.duration_minutes', 540)
        ->assertJsonPath('data.overtime_minutes', 60)
        ->assertJsonMissingPath('data.out_longitude');
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('clock.open', null));
});

it('notifies only active assigned supervisors and deduplicates rejected punches', function (): void {
    Notification::fake();
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $inactive = User::factory()->create(['is_active' => false])->assignRole(LogisticsRole::Supervisor->value);
    $outside = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->shift->jobSite->users()->attach([$supervisor->id, $inactive->id]);
    $position = array_replace($this->position, ['lat' => 41.0]);

    $this->actingAs($this->worker)->postJson('/api/attendances/clock-in', $position)
        ->assertUnprocessable()->assertJsonValidationErrors('location');
    $this->postJson('/api/attendances/clock-in', $position)->assertUnprocessable();
    $this->assertDatabaseCount('attendances', 0);

    Notification::assertSentTo($supervisor, AttendanceOutsideGeofenceNotification::class,
        fn ($notification, $channels) => $channels === ['database', 'mail']
            && $notification->toDatabase($supervisor)['shift_id'] === $this->shift->id
            && ! array_key_exists('lat', $notification->toDatabase($supervisor)));
    Notification::assertSentToTimes($supervisor, AttendanceOutsideGeofenceNotification::class, 1);
    Notification::assertNotSentTo([$inactive, $outside], AttendanceOutsideGeofenceNotification::class);
});

it('records separate punches and hours when a worker transfers to a second job site', function (): void {
    $firstSite = $this->shift->jobSite;
    $secondSite = JobSite::factory()->create(['name' => 'Brooklyn', 'latitude' => 40.69, 'longitude' => -73.99]);
    $secondShift = Shift::factory()->for($secondSite)->for($this->worker, 'worker')->create([
        'starts_at' => now()->addHours(9)->addMinutes(15),
        'ends_at' => now()->addHours(11),
    ]);
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $supervisor->jobSites()->attach([$firstSite->id, $secondSite->id]);
    $position = fn (Shift $shift, JobSite $site): array => [
        'shift_id' => $shift->id, 'lat' => $site->latitude, 'lng' => $site->longitude,
        'accuracy' => 20, 'timestamp' => now()->toIso8601String(),
    ];

    $this->actingAs($this->worker)->postJson('/api/attendances/clock-in', $position($this->shift, $firstSite))->assertCreated();
    $this->get('/worker/today')->assertInertia(fn (Assert $page) => $page
        ->has('shifts', 2)
        ->where('clock.open.shift_id', $this->shift->id)
        ->where('clock.shifts.0.id', $secondShift->id));

    $this->travelTo(CarbonImmutable::parse('2026-11-23T21:00:00Z'));
    $this->postJson('/api/attendances/clock-in', $position($secondShift, $secondSite))
        ->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    $this->postJson('/api/attendances/clock-out', $position($this->shift, $firstSite))
        ->assertOk()->assertJsonPath('data.duration_minutes', 480);

    $this->travelTo(CarbonImmutable::parse('2026-11-23T21:50:00Z'));
    $this->postJson('/api/attendances/clock-in', $position($secondShift, $firstSite))
        ->assertUnprocessable()->assertJsonValidationErrors('location');
    $this->postJson('/api/attendances/clock-in', $position($secondShift, $secondSite))
        ->assertCreated()->assertJsonPath('data.job_site_id', $secondSite->id);

    $this->travelTo(CarbonImmutable::parse('2026-11-23T23:00:00Z'));
    $this->postJson('/api/attendances/clock-out', $position($secondShift, $secondSite))
        ->assertOk()->assertJsonPath('data.overtime_minutes', 70);
    $this->assertDatabaseCount('attendances', 2);
    $this->get('/worker/today')->assertInertia(fn (Assert $page) => $page
        ->where('shifts.0.clocked_out_at', '2026-11-23T16:00:00-05:00')
        ->where('shifts.1.clocked_in_at', '2026-11-23T16:50:00-05:00')
        ->where('shifts.1.clocked_out_at', '2026-11-23T18:00:00-05:00'));
    $this->actingAs($supervisor)->get('/supervisor/team')->assertInertia(fn (Assert $page) => $page
        ->has('team', 2)
        ->where('team.0.shift_id', $this->shift->id)
        ->where('team.1.shift_id', $secondShift->id));
});

it('rejects punches for another worker on both routes and validates the sample', function (): void {
    Notification::fake();
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $supervisor->jobSites()->attach($this->shift->job_site_id);

    $this->actingAs($supervisor)->postJson('/api/attendances/clock-in', $this->position)->assertForbidden();
    $this->postJson('/api/attendances/clock-out', $this->position)->assertForbidden();
    $this->actingAs($this->worker)->postJson('/api/attendances/clock-in', array_replace($this->position, [
        'lat' => 91, 'timestamp' => '2026-11-23T08:00:00',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['lat', 'timestamp']);
    Notification::assertNothingSent();
});

it('resumes work at assigned sites after clocking out, including a return to the same site', function (): void {
    $first = $this->shift->jobSite;
    $second = JobSite::factory()->create(['status' => 'active', 'latitude' => 40.69, 'longitude' => -73.99]);
    $this->worker->jobSites()->attach($second);
    $payload = fn (JobSite $site): array => [
        'job_site_id' => $site->id, 'lat' => $site->latitude, 'lng' => $site->longitude,
        'accuracy' => 20, 'timestamp' => now()->toIso8601String(),
    ];
    $url = '/api/attendances/clock-in';

    $this->actingAs($this->worker)->postJson($url, $payload($second))->assertForbidden();
    $this->postJson($url, $this->position)->assertCreated();
    $this->travel(1)->hours();
    $this->postJson('/api/attendances/clock-out', array_replace($this->position, ['timestamp' => now()->toIso8601String()]))->assertOk();
    $this->get('/worker/today')->assertInertia(fn (Assert $page) => $page->has('clock.sites', 2));
    $this->postJson($url, $payload(JobSite::factory()->create()))->assertForbidden();
    $this->postJson($url, array_replace($payload($second), ['lat' => $first->latitude, 'lng' => $first->longitude]))
        ->assertUnprocessable()->assertJsonValidationErrors('location');
    $this->assertDatabaseCount('shifts', 1);

    $again = $this->postJson($url, $payload($first))->assertCreated()->json('data.shift_id');
    expect($again)->not->toBe($this->shift->id);
    $this->postJson($url, $payload($second))->assertUnprocessable();
    $this->travel(10)->minutes();
    $this->postJson('/api/attendances/clock-out', [
        ...array_diff_key($payload($first), ['job_site_id' => true]), 'shift_id' => $again,
    ])->assertOk()->assertJsonPath('data.duration_minutes', 10);

    $transfer = $this->postJson($url, $payload($second))->assertCreated()
        ->assertJsonPath('data.job_site_id', $second->id)->json('data.shift_id');
    expect(Shift::findOrFail($transfer)->is_unscheduled)->toBeTrue();
    $this->assertDatabaseCount('attendances', 3);
});

it('keeps an attendance open on an outside checkout and alerts its supervisor', function (): void {
    Notification::fake();
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $supervisor->jobSites()->attach($this->shift->job_site_id);
    $this->actingAs($this->worker)->postJson('/api/attendances/clock-in', $this->position)->assertCreated();
    $this->travelTo(CarbonImmutable::parse('2026-11-23T22:00:00Z'));

    $this->postJson('/api/attendances/clock-out', array_replace($this->position, [
        'lat' => 41, 'timestamp' => now()->toIso8601String(),
    ]))->assertUnprocessable()->assertJsonValidationErrors('location');
    expect(Attendance::sole()->clocked_out_at)->toBeNull();
    Notification::assertSentTo($supervisor, AttendanceOutsideGeofenceNotification::class,
        fn ($notification) => $notification->toDatabase($supervisor)['operation'] === 'salida');
});

it('enforces one open attendance per worker in the database', function (): void {
    $first = Attendance::factory()->for($this->shift)->create(['clocked_out_at' => now()->addHour()]);
    $open = fn () => Attendance::factory()->for(Shift::factory()->for($this->worker, 'worker'))->create();

    expect($first->clocked_out_at)->not->toBeNull();
    expect($open()->clocked_out_at)->toBeNull();
    expect($open)->toThrow(QueryException::class);
});
