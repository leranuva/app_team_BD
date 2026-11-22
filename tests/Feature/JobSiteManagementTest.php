<?php

use App\Enums\LogisticsRole;
use App\Filament\Resources\JobSites\Pages\EditJobSite;
use App\Filament\Resources\JobSites\Pages\ListJobSites;
use App\Filament\Resources\JobSites\Pages\ViewJobSite;
use App\Filament\Resources\JobSites\RelationManagers\ScopesRelationManager;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\ScopePhoto;
use App\Models\Shift;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed();
    Storage::fake('s3');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->site = JobSite::factory()->create([
        'name' => 'Brooklyn Heights',
        'client' => 'Cliente Norte',
        'latitude' => 40.703,
        'longitude' => -73.991,
        'geofence_meters' => 150,
        'status' => 'planned',
        'start_date' => '2026-11-01',
    ]);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create(['name' => 'Ana Torres'])->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->outsider = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->carpenter = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $this->helper = User::factory()->create()->assignRole(LogisticsRole::Helper->value);
});

it('lets managers change a site status and rejects a geofence outside 50 to 500 meters', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ListJobSites::class)
        ->callTableAction('changeStatus', $this->site, ['status' => 'completed']);

    expect($this->site->fresh()->status)->toBe('completed');

    Livewire::test(EditJobSite::class, ['record' => $this->site->getRouteKey()])
        ->fillForm(['geofence_meters' => 40])
        ->call('save')
        ->assertHasFormErrors(['geofence_meters']);

    Livewire::test(EditJobSite::class, ['record' => $this->site->getRouteKey()])
        ->fillForm(['geofence_meters' => 900])
        ->call('save')
        ->assertHasFormErrors(['geofence_meters']);

    expect($this->site->fresh()->geofence_meters)->toBe(150);
});

it('assigns site supervisors independently of the rest of the team', function (): void {
    $this->actingAs($this->admin);
    $this->site->users()->attach($this->carpenter);

    Livewire::test(EditJobSite::class, ['record' => $this->site->getRouteKey()])
        ->assertFormFieldExists('supervisors', fn (Select $field): bool => isset($field->getOptions()[$this->outsider->id])
            && ! isset($field->getOptions()[$this->carpenter->id]))
        ->assertFormSet(['supervisors' => [(string) $this->supervisor->id], 'users' => [(string) $this->carpenter->id]])
        ->fillForm(['supervisors' => [(string) $this->carpenter->id]])
        ->call('save')
        ->assertHasFormErrors(['supervisors.0']);

    Livewire::test(EditJobSite::class, ['record' => $this->site->getRouteKey()])
        ->fillForm(['supervisors' => [(string) $this->outsider->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->site->supervisors()->pluck('users.id')->all())->toBe([$this->outsider->id])
        ->and($this->site->users()->whereKey($this->carpenter->id)->exists())->toBeTrue();
});

it('shows the site map and keeps workers out of the Filament resource', function (): void {
    $scope = JobScope::create(['job_site_id' => $this->site->id, 'name' => 'Encofrado', 'status' => 'active', 'progress' => 25]);
    $this->actingAs($this->admin);

    Livewire::test(ViewJobSite::class, ['record' => $this->site->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('data-lat="40.703"', false)
        ->assertSee('data-lng="-73.991"', false)
        ->assertSee('data-radius="150"', false)
        ->assertSee('Open in external map')
        ->assertSee('ScopesRelationManager');

    Livewire::test(ScopesRelationManager::class, [
        'ownerRecord' => $this->site,
        'pageClass' => ViewJobSite::class,
    ])->assertCanSeeTableRecords([$scope]);

    $this->actingAs($this->supervisor)->get('/admin/job-sites/'.$this->site->id.'/edit')->assertForbidden();
    $this->actingAs($this->carpenter)->get('/admin/job-sites')->assertForbidden();
    $this->actingAs($this->helper)->get('/admin/job-sites')->assertForbidden();
    expect($scope->job_site_id)->toBe($this->site->id);
});

it('authorizes site reads by role and returns operational counts', function (): void {
    $active = JobScope::create(['job_site_id' => $this->site->id, 'name' => 'Encofrado', 'status' => 'active']);
    JobScope::create(['job_site_id' => $this->site->id, 'name' => 'Pending', 'status' => 'pending']);
    ScopePhoto::factory()->create(['job_scope_id' => $active->id, 'uploaded_by' => $this->admin->id]);
    $other = JobSite::factory()->create(['name' => 'Queens']);
    Shift::factory()->create([
        'job_site_id' => $this->site->id,
        'user_id' => $this->carpenter->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(8),
        'status' => 'programmed',
    ]);

    $this->actingAs($this->admin)->getJson('/api/job-sites')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Brooklyn Heights', 'client_name' => 'Cliente Norte', 'scopes_count' => 1, 'photos_count' => 1])
        ->assertJsonFragment(['name' => 'Queens']);

    $this->actingAs($this->supervisor)->getJson('/api/job-sites/'.$this->site->id)
        ->assertOk()
        ->assertJsonPath('data.geofence_radius_m', 150)
        ->assertJsonPath('data.lat', 40.703)
        ->assertJsonPath('data.supervisors.0.name', 'Ana Torres');

    $this->actingAs($this->outsider)->getJson('/api/job-sites/'.$this->site->id)->assertForbidden();
    $this->actingAs($this->outsider)->getJson('/api/job-sites')->assertOk()->assertJsonMissing(['name' => 'Brooklyn Heights']);

    $this->actingAs($this->carpenter)->getJson('/api/job-sites')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Brooklyn Heights'])
        ->assertJsonMissing(['name' => 'Queens']);
    $this->actingAs($this->helper)->getJson('/api/job-sites/'.$other->id)->assertForbidden();
});

it('limits job site reads to 60 per minute', function (): void {
    $this->actingAs($this->admin);
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->getJson('/api/job-sites')->assertOk();
    }
    $this->getJson('/api/job-sites')->assertStatus(429);
    Cache::flush();
});
