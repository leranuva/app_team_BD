<?php

use App\Enums\LogisticsRole;
use App\Http\Resources\AttendanceResource;
use App\Jobs\RecalculateWeeklyHours;
use App\Models\Attendance;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 15:00:00', 'America/New_York'));
    $this->site = JobSite::factory()->create();
    $this->other = JobSite::factory()->create();
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->outsider = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->outsider->jobSites()->attach($this->other);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->shift = Shift::factory()->create([
        'job_site_id' => $this->site->id,
        'starts_at' => CarbonImmutable::parse('2026-11-23 08:00:00', 'America/New_York'),
        'ends_at' => CarbonImmutable::parse('2026-11-23 16:00:00', 'America/New_York'),
    ]);
    $this->worker = $this->shift->worker;
    $this->attendance = Attendance::factory()->create([
        'shift_id' => $this->shift->id,
        'clocked_in_at' => $this->shift->starts_at,
        'clocked_out_at' => $this->shift->ends_at,
    ]);
    $this->payload = [
        'clock_in_at' => '2026-11-23T08:00:00-05:00',
        'clock_out_at' => '2026-11-23T17:00:00-05:00',
        'correction_reason' => 'Olvidó fichar la salida real',
    ];
});

it('authorizes correction for the site supervisor and an administrator only', function (): void {
    expect($this->supervisor->can('correct', $this->attendance))->toBeTrue()
        ->and($this->admin->can('correct', $this->attendance))->toBeTrue()
        ->and($this->outsider->can('correct', $this->attendance))->toBeFalse()
        ->and($this->worker->can('correct', $this->attendance))->toBeFalse();
});

it('exposes can_correct on the attendance resource for each role', function (): void {
    $flags = [];
    foreach (['supervisor' => $this->supervisor, 'admin' => $this->admin, 'outsider' => $this->outsider, 'worker' => $this->worker] as $key => $user) {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $flags[$key] = (new AttendanceResource($this->attendance))->toArray($request)['can_correct'];
    }

    expect($flags)->toBe([
        'supervisor' => true,
        'admin' => true,
        'outsider' => false,
        'worker' => false,
    ]);
});

it('corrects an attendance for the site supervisor and queues the weekly recalculation', function (): void {
    Bus::fake();

    $this->actingAs($this->supervisor)
        ->patchJson(route('api.attendances.correct', $this->attendance), $this->payload)
        ->assertOk()
        ->assertJsonPath('data.duration_minutes', 540)
        ->assertJsonPath('data.overtime_minutes', 60)
        ->assertJsonPath('data.status', 'corrected')
        ->assertJsonPath('data.correction_reason', $this->payload['correction_reason'])
        ->assertJsonPath('data.corrected_by', $this->supervisor->id)
        ->assertJsonPath('data.can_correct', true);

    Bus::assertDispatched(RecalculateWeeklyHours::class, function (RecalculateWeeklyHours $job): bool {
        return $job->userId === $this->worker->id && $job->queue === 'attendance';
    });
});

it('rejects a correction from another supervisor, the worker, a bad request and a missing id', function (): void {
    $this->actingAs($this->outsider)
        ->patchJson(route('api.attendances.correct', $this->attendance), $this->payload)
        ->assertForbidden();

    $this->actingAs($this->worker)
        ->patchJson(route('api.attendances.correct', $this->attendance), $this->payload)
        ->assertForbidden();

    $this->actingAs($this->supervisor)
        ->patchJson(route('api.attendances.correct', $this->attendance), [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.correction_reason.0', 'A correction reason is required.');

    $this->patchJson(route('api.attendances.correct', $this->attendance), [
        ...$this->payload,
        'correction_reason' => 'corto',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.correction_reason.0', 'The reason must contain at least 10 characters.');

    $this->patchJson(route('api.attendances.correct', $this->attendance), [
        ...$this->payload,
        'clock_out_at' => '2026-11-23T07:00:00-05:00',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.clock_out_at.0', 'Clock-out must be after clock-in.');

    $this->patchJson(route('api.attendances.correct', 999999), $this->payload)->assertNotFound();
});
