<?php

use App\Enums\LogisticsRole;
use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Filament\Resources\Shifts\Pages\CreateShift;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->site = JobSite::factory()->create();
    $this->worker = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $this->worker->jobSites()->attach($this->site);
    $this->data = [
        'job_site_id' => $this->site->id,
        'role_required' => ShiftRole::Carpenter->value,
        'user_id' => $this->worker->id,
        'status' => ShiftStatus::Programmed->value,
        'starts_at' => '2026-11-24 13:00:00',
        'ends_at' => '2026-11-24 17:00:00',
    ];
    $this->service = app(ShiftService::class);
});

it('saves local Filament dates in UTC and blocks a conflicting form submission', function (): void {
    $this->actingAs($this->admin);

    $local = array_replace($this->data, [
        'starts_at' => '2026-11-24 08:00:00',
        'ends_at' => '2026-11-24 12:00:00',
    ]);

    Livewire::test(CreateShift::class)->fillForm($local)->call('create')->assertHasNoFormErrors();
    expect(Shift::sole()->starts_at->format('Y-m-d H:i:s'))->toBe($this->data['starts_at']);

    Livewire::test(CreateShift::class)->fillForm($local)->call('create')->assertHasFormErrors(['starts_at']);
    expect(Shift::count())->toBe(1);
});

it('rejects total and partial overlaps and accepts the exact boundary', function (): void {
    $shift = $this->service->save($this->admin, null, $this->data);

    foreach ([
        ['starts_at' => '2026-11-24 14:00:00', 'ends_at' => '2026-11-24 16:00:00'],
        ['starts_at' => '2026-11-24 16:00:00', 'ends_at' => '2026-11-24 18:00:00'],
    ] as $range) {
        expect(fn () => $this->service->save($this->admin, null, array_replace($this->data, $range)))
            ->toThrow(ValidationException::class);
    }

    expect($this->service->save($this->admin, $shift, $this->data)->is($shift))->toBeTrue();
    $adjacent = array_replace($this->data, ['starts_at' => $this->data['ends_at'], 'ends_at' => '2026-11-24 19:00:00']);
    expect($this->service->save($this->admin, null, $adjacent)->exists)->toBeTrue();
});

it('reports partial bulk success and audits cancellation with its reason', function (): void {
    $this->actingAs($this->admin);
    $shift = $this->service->save($this->admin, null, $this->data);
    $other = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $other->jobSites()->attach($this->site);

    $results = $this->service->assignMany($this->admin, $this->data, [$this->worker->id, $other->id]);
    expect($results[$this->worker->id])->toContain('solapa')->and($results[$other->id])->toBeNull();

    $available = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $available->jobSites()->attach($this->site);
    Livewire::test(ListShifts::class)
        ->callAction('assignMany', [
            'job_site_id' => $this->site->id,
            'role_required' => ShiftRole::Carpenter->value,
            'workers' => [$this->worker->id, $available->id],
            'starts_at' => '2026-11-24 08:00:00',
            'ends_at' => '2026-11-24 12:00:00',
            'status' => ShiftStatus::Programmed->value,
        ])
        ->assertHasNoActionErrors();
    expect(Shift::where('user_id', $available->id)->count())->toBe(1)
        ->and(Shift::where('user_id', $this->worker->id)->count())->toBe(1);

    Livewire::test(ListShifts::class)
        ->callAction(TestAction::make('cancel')->table($shift), ['reason' => 'Cierre de obra'])
        ->assertHasNoActionErrors();

    expect($shift->refresh()->status)->toBe(ShiftStatus::Cancelled);
    $activity = Activity::forSubject($shift)->where('event', 'updated')->sole();
    expect($activity->log_name)->toBe('shifts')
        ->and($activity->getExtraProperty('attributes.cancellation_reason'))->toBe('Cierre de obra');
    expect($this->service->save($this->admin, null, $this->data)->exists)->toBeTrue();
});

it('protects site boundaries, scope assignment and deleted workers', function (): void {
    $shift = $this->service->save($this->admin, null, $this->data);
    $supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $outside = JobSite::factory()->create();
    $supervisor->jobSites()->attach($outside);

    expect($supervisor->can('update', $shift))->toBeFalse()
        ->and($this->admin->can('update', $shift))->toBeTrue();
    expect(fn () => $this->service->save($supervisor, null, $this->data))->toThrow(AuthorizationException::class);

    $scope = JobScope::create(['job_site_id' => $outside->id, 'name' => 'Otro alcance']);
    expect(fn () => $this->service->save($this->admin, $shift, $this->data + ['job_scope_id' => $scope->id]))
        ->toThrow(ValidationException::class);

    $this->worker->delete();
    expect($this->admin->can('update', $shift))->toBeFalse();
});
