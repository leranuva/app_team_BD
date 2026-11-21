<?php

use App\Enums\LogisticsRole;
use App\Models\Attendance;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\AttendanceOutsideGeofenceNotification;
use Carbon\CarbonImmutable;
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
