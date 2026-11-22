<?php

use App\Enums\LogisticsRole;
use App\Filament\Resources\JobScopes\Pages\CreateJobScope;
use App\Filament\Resources\JobScopes\Pages\ListJobScopes;
use App\Filament\Resources\JobScopes\Pages\ViewJobScope;
use App\Filament\Resources\JobScopes\RelationManagers\ShiftsRelationManager;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->site = JobSite::factory()->create(['name' => 'Brooklyn Heights']);
    $this->other = JobSite::factory()->create(['name' => 'Queens']);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create(['name' => 'Ana Torres'])->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->carpenter = User::factory()->create(['name' => 'Luis Peña'])->assignRole(LogisticsRole::Carpenter->value);
    $this->scope = JobScope::create([
        'job_site_id' => $this->site->id,
        'supervisor_id' => $this->supervisor->id,
        'name' => 'Encofrado',
        'status' => 'active',
        'progress' => 40,
    ]);
});

it('validates the responsible supervisor and a progress of 100', function (): void {
    $this->actingAs($this->supervisor);

    Livewire::test(CreateJobScope::class)
        ->fillForm([
            'job_site_id' => $this->site->id,
            'name' => 'Losa',
            'status' => 'pending',
            'progress' => 100,
            'supervisor_id' => $this->carpenter->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['progress', 'supervisor_id']);

    Livewire::test(CreateJobScope::class)
        ->fillForm([
            'job_site_id' => $this->site->id,
            'name' => 'Losa',
            'status' => 'completed',
            'progress' => 100,
            'supervisor_id' => $this->supervisor->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(JobScope::query()->where('name', 'Losa')->value('status'))->toBe('completed');
    $this->actingAs($this->carpenter)->get('/admin/job-scopes')->assertForbidden();
    expect($this->supervisor->can('delete', $this->scope))->toBeFalse()
        ->and($this->admin->can('delete', $this->scope))->toBeTrue()
        ->and($this->carpenter->can('viewAny', JobScope::class))->toBeFalse();
});

it('duplicates a scope, confirms completion and links the React gallery', function (): void {
    $shift = Shift::factory()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'user_id' => $this->carpenter->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(8),
    ]);
    $this->actingAs($this->admin);

    Livewire::test(ListJobScopes::class)
        ->callTableAction('duplicate', $this->scope);
    expect(JobScope::query()->where('name', 'Encofrado (copia)')->value('progress'))->toBe(0);

    Livewire::test(ListJobScopes::class)
        ->callTableAction('changeStatus', $this->scope, ['status' => 'completed']);
    expect($this->scope->fresh()->status)->toBe('completed');

    Livewire::test(ViewJobScope::class, ['record' => $this->scope->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('ScopePhotosRelationManager')
        ->assertSee('Ver fotos')
        ->assertSee(route('job-scopes.show', $this->scope), false);

    Livewire::test(ShiftsRelationManager::class, [
        'ownerRecord' => $this->scope,
        'pageClass' => ViewJobScope::class,
    ])->assertCanSeeTableRecords([$shift]);
});

it('filters scope reads and reflects who can edit', function (): void {
    JobScope::create(['job_site_id' => $this->other->id, 'name' => 'Ajeno', 'status' => 'pending']);
    Shift::factory()->create([
        'job_site_id' => $this->site->id,
        'user_id' => $this->carpenter->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(8),
    ]);

    $this->actingAs($this->supervisor)->getJson('/api/job-scopes?status=active&job_site_id='.$this->site->id)
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Encofrado')
        ->assertJsonPath('data.0.progress_percent', 40)
        ->assertJsonPath('data.0.can_edit', true)
        ->assertJsonPath('data.0.responsible.name', 'Ana Torres')
        ->assertJsonMissing(['name' => 'Ajeno']);

    $this->actingAs($this->carpenter)->getJson('/api/job-scopes/'.$this->scope->id)
        ->assertOk()
        ->assertJsonPath('data.can_edit', false);
    $this->actingAs($this->carpenter)->getJson('/api/job-scopes?job_site_id='.$this->other->id)->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole(LogisticsRole::Helper->value))
        ->getJson('/api/job-scopes/'.$this->scope->id)
        ->assertForbidden();
});
