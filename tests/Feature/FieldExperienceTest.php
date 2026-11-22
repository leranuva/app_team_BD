<?php

use App\Enums\LogisticsRole;
use App\Models\Attendance;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 15:00:00', 'America/New_York'));
    $this->site = JobSite::factory()->create(['name' => 'Queens', 'address' => '31st Ave']);
    $this->supervisor = User::factory()->create(['name' => 'Ana Torres'])->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->scope = JobScope::query()->create([
        'job_site_id' => $this->site->id,
        'supervisor_id' => $this->supervisor->id,
        'name' => 'Encofrado',
        'status' => 'active',
        'progress' => 40,
    ]);
    $this->shift = Shift::factory()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'starts_at' => CarbonImmutable::parse('2026-11-23 08:00:00', 'America/New_York'),
        'ends_at' => CarbonImmutable::parse('2026-11-23 16:00:00', 'America/New_York'),
        'notes' => 'Llevar andamio',
    ]);
    $this->worker = $this->shift->worker;
});

it('shows the worker today page with the shift and assigned materials', function (): void {
    $list = MaterialList::factory()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'assigned_to' => $this->worker->id,
        'requested_by' => $this->worker->id,
    ]);
    $item = MaterialListItem::factory()->create([
        'material_list_id' => $list->id,
        'requested_quantity' => 2,
    ]);

    $this->actingAs($this->worker)->get(route('worker.today'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Worker/Today')
            ->where('timeZone', 'America/New_York')
            ->where('shift.id', $this->shift->id)
            ->where('shift.job_site.name', 'Queens')
            ->where('shift.job_site.address', '31st Ave')
            ->where('shift.job_scope.name', 'Encofrado')
            ->where('shift.supervisor', 'Ana Torres')
            ->where('shift.role_required', 'Carpintero')
            ->where('materials.0.id', $item->id)
            ->where('materials.0.material', $item->material->name));
});

it('limits worker history to the last four weeks', function (): void {
    Attendance::factory()->create([
        'shift_id' => $this->shift->id,
        'clocked_out_at' => CarbonImmutable::parse('2026-11-23 16:00:00', 'America/New_York'),
        'duration_minutes' => 480,
    ]);
    $old = Shift::factory()->create([
        'job_site_id' => $this->site->id,
        'user_id' => $this->worker->id,
        'starts_at' => CarbonImmutable::parse('2026-10-01 08:00:00', 'America/New_York'),
        'ends_at' => CarbonImmutable::parse('2026-10-01 16:00:00', 'America/New_York'),
    ]);
    Attendance::factory()->create([
        'shift_id' => $old->id,
        'clocked_out_at' => CarbonImmutable::parse('2026-10-01 16:00:00', 'America/New_York'),
        'duration_minutes' => 480,
    ]);

    $this->actingAs($this->worker)
        ->get(route('worker.schedule'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Worker/Schedule'));

    $this->get(route('worker.history'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Worker/History')
            ->has('attendances.data', 1)
            ->missing('attendances.data.0.in_latitude')
            ->missing('attendances.data.0.shift_id'));
});

it('lets a supervisor see the team and keeps hour approval read only', function (): void {
    $this->actingAs($this->worker)->get(route('supervisor.team'))->assertForbidden();

    $this->actingAs($this->supervisor)->get(route('supervisor.team'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Supervisor/TeamToday')
            ->where('team.0.name', $this->worker->name)
            ->where('team.0.status', 'scheduled')
            ->where('team.0.can_correct', false));

    $this->get(route('supervisor.team', ['job_site_id' => JobSite::factory()->create()->id]))->assertForbidden();

    $this->get(route('supervisor.scopes'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Supervisor/ScopeProgress')
            ->where('scopes.0.name', 'Encofrado')
            ->where('scopes.0.can_edit', true)
            ->where('scopes.0.progress_percent', 40));

    $this->get(route('supervisor.hours'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Supervisor/HoursApproval')
            ->where('approval.available', false)
            ->where('approval.note', 'La aprobación de horas se gestiona fuera del sistema en este bloque.'));
});

it('updates scope progress only when 100 percent matches a completed scope', function (): void {
    $this->actingAs($this->worker)->patchJson(route('api.job-scopes.update', $this->scope), ['progress' => 50])->assertForbidden();

    $this->actingAs($this->supervisor)
        ->patchJson(route('api.job-scopes.update', $this->scope), ['progress' => 100])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('progress');

    $this->patchJson(route('api.job-scopes.update', $this->scope), ['progress' => 55])
        ->assertOk()
        ->assertJsonPath('data.progress_percent', 55);
    expect($this->scope->refresh()->progress)->toBe(55);

    $this->scope->update(['status' => 'completed']);
    $this->patchJson(route('api.job-scopes.update', $this->scope), ['progress' => 100])
        ->assertOk()
        ->assertJsonPath('data.progress_percent', 100);

    $this->patchJson(route('api.job-scopes.update', 999999), ['progress' => 10])->assertNotFound();
});

it('lets a supervisor correct an attendance and keeps the reason in the audit log', function (): void {
    $in = CarbonImmutable::parse('2026-11-23 08:00:00', 'America/New_York');
    $attendance = Attendance::factory()->create([
        'shift_id' => $this->shift->id,
        'clocked_in_at' => $in,
        'clocked_out_at' => $in->addHours(8),
        'duration_minutes' => 480,
        'regular_minutes' => 480,
        'overtime_minutes' => 0,
    ]);
    $payload = [
        'clock_in_at' => $in->toIso8601String(),
        'clock_out_at' => $in->addHours(9)->toIso8601String(),
        'correction_reason' => 'Olvidó la salida',
    ];

    $this->actingAs($this->worker)->patchJson(route('api.attendances.correct', $attendance), $payload)->assertForbidden();
    $outsider = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->actingAs($outsider)->patchJson(route('api.attendances.correct', $attendance), $payload)->assertForbidden();

    $this->actingAs($this->supervisor)
        ->patchJson(route('api.attendances.correct', $attendance), ['clock_in_at' => $payload['clock_in_at'], 'clock_out_at' => $payload['clock_out_at']])
        ->assertUnprocessable()->assertJsonValidationErrors('correction_reason');

    $this->patchJson(route('api.attendances.correct', $attendance), [
        ...$payload,
        'clock_out_at' => $in->subHour()->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors('clock_out_at');

    $this->patchJson(route('api.attendances.correct', $attendance), $payload)
        ->assertOk()
        ->assertJsonPath('data.duration_minutes', 540)
        ->assertJsonPath('data.overtime_minutes', 60);

    $this->get(route('supervisor.team'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('team.0.attendance_id', $attendance->id)
            ->where('team.0.can_correct', true)
            ->where('team.0.status', 'completed'));

    $log = Activity::query()->where('subject_type', $attendance->getMorphClass())
        ->where('subject_id', $attendance->id)
        ->where('description', 'attendance.corrected')
        ->first();
    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->supervisor->id)
        ->and($log->properties->get('reason'))->toBe('Olvidó la salida');
});
