<?php

use App\Enums\LogisticsRole;
use App\Models\Attendance;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceCalculatorService;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->service = app(AttendanceService::class);
    $this->calculator = app(AttendanceCalculatorService::class);
    $this->shift = Shift::factory()->create([
        'starts_at' => '2026-11-23 13:00:00',
        'ends_at' => '2026-11-23 22:00:00',
    ]);
    $this->worker = $this->shift->worker;
});

it('clocks in and out with a geofence flag and records weekly buckets and the actor', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-23T13:00:00Z'));
    $entry = $this->service->clockIn($this->worker, $this->shift->id, 40.75, -73.9, 120);
    expect($entry->geofence_flagged)->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-11-23T22:00:00Z'));
    $closed = $this->service->clockOut($this->worker, $this->shift->id, 40.75, -73.9, 25);
    expect($closed->duration_minutes)->toBe(540)
        ->and($closed->regular_minutes)->toBe(480)
        ->and($closed->overtime_minutes)->toBe(60)
        ->and($closed->double_time_minutes)->toBe(0)
        ->and($closed->geofence_flagged)->toBeTrue();

    $audit = Activity::forSubject($closed)->where('event', 'updated')->sole();
    expect($audit->causer_id)->toBe($this->worker->id)
        ->and($audit->getExtraProperty('attributes.duration_minutes'))->toBe(540)
        ->and($audit->getExtraProperty('attributes.out_latitude'))->toBeNull();
});

it('rejects other workers, an outside position, stale samples and duplicate open entries', function (): void {
    $other = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $other->jobSites()->attach($this->shift->job_site_id);
    $this->travelTo(CarbonImmutable::parse('2026-11-23T13:00:00Z'));
    expect(fn () => $this->service->clockIn($other, $this->shift->id, 40.75, -73.9, 20))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->clockIn($this->worker, $this->shift->id, 41.0, -73.9, 20))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->service->clockIn($this->worker, $this->shift->id, 40.75, -73.9, 20, now()->subMinutes(3)))
        ->toThrow(ValidationException::class);
    expect(Attendance::count())->toBe(0);

    $this->service->clockIn($this->worker, $this->shift->id, 40.75, -73.9, 20);
    $otherShift = Shift::factory()->for($this->worker, 'worker')->create([
        'starts_at' => $this->shift->starts_at, 'ends_at' => $this->shift->ends_at,
    ]);
    expect(fn () => $this->service->clockIn($this->worker, $otherShift->id, 40.75, -73.9, 20))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->service->clockOut($other, $this->shift->id, 40.75, -73.9, 20))
        ->toThrow(AuthorizationException::class);
    expect(Attendance::sole()->clocked_out_at)->toBeNull();
});

it('counts all hours toward the weekly limit and avoids stacking overtime buckets', function (): void {
    $history = [];
    foreach (range(23, 26) as $day) {
        $history[] = new Attendance([
            'clocked_in_at' => "2026-11-{$day} 13:00:00",
            'clocked_out_at' => "2026-11-{$day} 22:00:00",
        ]);
    }
    config()->set('attendance.daily_double_time_after_minutes', 720);
    $minutes = $this->calculator->calculate(
        CarbonImmutable::parse('2026-11-27T13:00:00Z'), CarbonImmutable::parse('2026-11-28T02:00:00Z'), $history,
    );
    expect($minutes)->toBe([
        'duration_minutes' => 780, 'regular_minutes' => 240, 'overtime_minutes' => 480, 'double_time_minutes' => 60,
    ])->and($this->calculator->estimateGrossPay($minutes, '20.00'))->toBe('360.00');
});

it('includes prior shifts at other sites when closing attendance', function (): void {
    $otherSite = JobSite::factory()->create();
    // Fill five prior eight-hour days through distinct shifts at the other site.
    foreach (range(23, 27) as $day) {
        $shift = Shift::factory()->for($this->worker, 'worker')->for($otherSite, 'jobSite')->create([
            'starts_at' => "2026-11-{$day} 13:00:00", 'ends_at' => "2026-11-{$day} 21:00:00",
        ]);
        Attendance::factory()->for($shift, 'shift')->create(['clocked_out_at' => $shift->ends_at]);
    }
    $this->shift->update(['starts_at' => '2026-11-28 13:00:00', 'ends_at' => '2026-11-28 14:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-11-28T12:29:00Z'));
    expect(fn () => $this->service->clockIn($this->worker, $this->shift->id, 40.75, -73.9, 20))
        ->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-28T13:00:00Z'));
    $this->service->clockIn($this->worker, $this->shift->id, 40.75, -73.9, 20);
    $this->travelTo(CarbonImmutable::parse('2026-11-28T14:00:00Z'));
    $closed = $this->service->clockOut($this->worker, $this->shift->id, 40.75, -73.9, 20);
    expect($closed->regular_minutes)->toBe(0)->and($closed->overtime_minutes)->toBe(60);
});

it('splits New York workdays and measures actual elapsed time across DST', function (): void {
    expect($this->calculator->calculate(
        CarbonImmutable::parse('2026-03-08T01:00:00-05:00'),
        CarbonImmutable::parse('2026-03-08T04:00:00-04:00'),
    )['duration_minutes'])->toBe(120);
    expect($this->calculator->calculate(
        CarbonImmutable::parse('2026-11-01T00:00:00-04:00'),
        CarbonImmutable::parse('2026-11-01T03:00:00-05:00'),
    )['duration_minutes'])->toBe(240);

    $minutes = $this->calculator->calculate(
        CarbonImmutable::parse('2026-11-29T20:00:00-05:00'),
        CarbonImmutable::parse('2026-11-30T08:00:00-05:00'),
    );
    expect($minutes['regular_minutes'])->toBe(720)->and($minutes['overtime_minutes'])->toBe(0);
});
