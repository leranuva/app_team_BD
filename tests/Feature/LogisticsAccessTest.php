<?php

namespace Tests\Feature;

use App\Enums\LogisticsRole;
use App\Filament\Resources\JobSites\Pages\CreateJobSite;
use App\Models\Attendance;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\Shift;
use App\Models\User;
use App\Policies\JobSitePolicy;
use App\Policies\LogisticsRecordPolicy;
use App\Policies\ManagementRolePolicy;
use Filament\Forms\Components\Select;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LogisticsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_only_internal_accounts_can_enter_the_panels(): void
    {
        $this->get('/admin/login')->assertOk();
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->post('/register')->assertNotFound();

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();

        foreach ([LogisticsRole::Helper, LogisticsRole::Carpenter] as $role) {
            $this->actingAs($this->worker($role))->get('/admin')->assertForbidden();
        }

        $this->actingAs($this->worker(LogisticsRole::Administrator))->get('/admin')->assertOk();
        $this->get('/admin/shield/roles')->assertForbidden();

        $this->actingAs($this->worker(LogisticsRole::SuperAdmin))->get('/admin/shield/roles')->assertOk();
    }

    public function test_shield_preserves_application_policies(): void
    {
        $this->assertFalse(config('filament-shield.policies.generate'));
        $this->assertInstanceOf(ManagementRolePolicy::class, Gate::getPolicyFor(Role::class));
        $this->assertInstanceOf(JobSitePolicy::class, Gate::getPolicyFor(JobSite::class));
        $this->assertInstanceOf(LogisticsRecordPolicy::class, Gate::getPolicyFor(Attendance::class));
    }

    public function test_supervisors_can_read_only_their_sites(): void
    {
        $supervisor = $this->worker(LogisticsRole::Supervisor);
        $assigned = $this->site('Assigned');
        $outside = $this->site('Outside');
        $assigned->users()->attach($supervisor);

        $this->actingAs($supervisor)->get('/admin/job-sites')->assertOk()
            ->assertSee('Assigned')->assertDontSee('Outside');
        $this->assertSame([$assigned->id], JobSite::pluck('id')->all());
        $teammate = $this->worker(LogisticsRole::Helper);
        $outsider = $this->worker(LogisticsRole::Helper);
        $assigned->users()->attach($teammate);
        $outside->users()->attach($outsider);
        $this->assertTrue($supervisor->can('view', $teammate));
        $this->assertFalse($supervisor->can('view', $outsider));
        $this->assertFalse($supervisor->can('viewPrivateDetails', $teammate));
        $this->get('/admin/job-sites/'.$assigned->id)->assertOk();
        $this->get('/admin/job-sites/'.$outside->id)->assertNotFound();
        $this->get('/admin/job-sites/'.$assigned->id.'/edit')->assertForbidden();
        $this->get('/admin/job-sites/create')->assertForbidden();
    }

    public function test_carpenters_only_receive_their_own_assignments_in_their_sites(): void
    {
        $worker = $this->worker(LogisticsRole::Carpenter);
        $other = $this->worker(LogisticsRole::Helper);
        $site = $this->site('Assigned');
        $outside = $this->site('Outside');
        $site->users()->attach([$worker->id, $other->id]);

        $mine = $this->shift($worker, $site);
        $theirs = $this->shift($other, $site);
        $outsideShift = $this->shift($worker, $outside);
        MaterialList::create(['job_site_id' => $site->id, 'assigned_to' => $worker->id, 'name' => 'My list']);
        MaterialList::create(['job_site_id' => $site->id, 'assigned_to' => $other->id, 'name' => 'Other list']);
        MaterialList::create(['job_site_id' => $outside->id, 'assigned_to' => $worker->id, 'name' => 'Outside list']);

        $this->assertTrue($worker->can('view', $mine));
        $this->assertFalse($worker->can('view', $theirs));
        $this->assertFalse($worker->can('view', $outsideShift));

        $this->actingAs($worker)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('timeZone', 'America/New_York')
            ->has('shifts', 1)
            ->where('shifts.0.id', $mine->id)
            ->has('lists', 1)
            ->where('lists.0.name', 'My list')
            ->has('sites', 0));
    }

    public function test_worker_dashboard_lists_assigned_sites_without_shifts(): void
    {
        $worker = $this->worker(LogisticsRole::Carpenter);
        $primary = $this->site('Brooklyn');
        $secondary = $this->site('Queens');
        $this->site('Unassigned');
        $worker->update(['primary_job_site_id' => $primary->id]);
        $worker->jobSites()->attach($secondary);

        $this->actingAs($worker)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('shifts', 0)
            ->has('mySites', 2)
            ->where('mySites.0.id', $primary->id)
            ->where('mySites.0.read_only', true)
            ->where('mySites.1.id', $secondary->id));
    }

    public function test_attendance_must_match_the_shift_site_and_worker(): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $site = $this->site('Assigned');
        $otherSite = $this->site('Outside');
        $shift = $this->shift($worker, $site);
        $attendance = [
            'shift_id' => $shift->id,
            'job_site_id' => $site->id,
            'user_id' => $worker->id,
            'clocked_in_at' => $shift->starts_at,
            'in_latitude' => $site->latitude,
            'in_longitude' => $site->longitude,
        ];

        foreach ([['job_site_id' => $otherSite->id], ['user_id' => $otherWorker->id]] as $invalid) {
            try {
                DB::transaction(fn () => Attendance::create(array_replace($attendance, $invalid)));
                $this->fail('Attendance with a mismatched site or worker was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
            }
        }

        $record = Attendance::create($attendance);
        $this->assertTrue($record->shift->is($shift));
        $this->assertTrue($record->jobSite->is($site));
        $this->assertTrue($record->user->is($worker));
    }

    public function test_an_administrator_can_create_a_site_and_assign_a_worker(): void
    {
        $worker = $this->worker(LogisticsRole::Carpenter);
        $this->actingAs($this->worker(LogisticsRole::Administrator));

        Livewire::test(CreateJobSite::class)
            ->assertFormFieldExists('users', fn (Select $field): bool => $field->isPreloaded()
                && ($field->getOptions()[$worker->id] ?? null) === $worker->name)
            ->fillForm([
                'name' => 'Queens',
                'address' => 'Queens, NY',
                'client' => 'Customer',
                'latitude' => 40.75,
                'longitude' => -73.9,
                'geofence_meters' => 150,
                'status' => 'active',
                'base_budget' => 20000,
                'users' => [(string) $worker->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $site = JobSite::firstOrFail();
        $this->assertSame('Queens', $site->name);
        $this->assertTrue($site->users()->whereKey($worker->id)->exists());
    }

    public function test_database_rejects_a_change_order_linked_to_another_sites_scope(): void
    {
        $worker = $this->worker(LogisticsRole::Supervisor);
        $site = $this->site('Assigned');
        $outside = $this->site('Outside');
        $scope = JobScope::create(['job_site_id' => $outside->id, 'name' => 'Scope']);

        $this->expectException(QueryException::class);

        ChangeOrder::create([
            'job_site_id' => $site->id,
            'job_scope_id' => $scope->id,
            'created_by' => $worker->id,
            'description' => 'Conflicting site',
        ]);
    }

    public function test_shift_changes_are_audited(): void
    {
        $supervisor = $this->worker(LogisticsRole::Supervisor);
        $worker = $this->worker(LogisticsRole::Helper);
        $site = $this->site('Assigned');
        $site->users()->attach([$supervisor->id, $worker->id]);
        $this->actingAs($supervisor);

        $shift = $this->shift($worker, $site);
        $oldEnd = $shift->ends_at;
        $newEnd = $oldEnd->addHours(2);
        $shift->update(['ends_at' => $newEnd]);

        $activity = Activity::forSubject($shift)->where('event', 'updated')->sole();
        $this->assertTrue($activity->causer->is($supervisor));
        $this->assertTrue($activity->subject->is($shift));
        $this->assertSame([
            'attributes' => ['ends_at' => $newEnd->toJSON()],
            'old' => ['ends_at' => $oldEnd->toJSON()],
        ], $activity->changes->all());
    }

    private function worker(LogisticsRole $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }

    private function site(string $name): JobSite
    {
        return JobSite::create([
            'name' => $name, 'address' => 'New York, NY', 'client' => 'Customer',
            'latitude' => 40.75, 'longitude' => -73.9,
        ]);
    }

    private function shift(User $user, JobSite $site): Shift
    {
        return Shift::create([
            'job_site_id' => $site->id, 'user_id' => $user->id,
            'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2),
        ]);
    }
}
