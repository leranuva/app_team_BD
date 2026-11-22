<?php

use App\Enums\LogisticsRole;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\ScopePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::create(['job_site_id' => $this->site->id, 'name' => 'Encofrado']);
    $this->photo = ScopePhoto::factory()->create(['job_scope_id' => $this->scope->id, 'uploaded_by' => User::factory()]);
});

it('authorizes photo actions by role and job_site_user membership', function (): void {
    $abilities = ['viewAny', 'view', 'create', 'archive', 'restore', 'delete'];
    $privileged = [LogisticsRole::SuperAdmin, LogisticsRole::Administrator, LogisticsRole::Supervisor];

    foreach (LogisticsRole::cases() as $role) {
        $user = User::factory()->create()->assignRole($role->value);
        $user->jobSites()->attach($this->site);

        foreach ($abilities as $ability) {
            $allowed = in_array($role, $privileged, true) && $ability !== 'delete';
            $subject = in_array($ability, ['viewAny', 'create'], true)
                ? [ScopePhoto::class, $this->scope]
                : $this->photo;

            expect($user->can($ability, $subject))->toBe($allowed, "{$role->value} {$ability}");
        }
    }

    $outsider = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    foreach ($abilities as $ability) {
        $subject = in_array($ability, ['viewAny', 'create'], true)
            ? [ScopePhoto::class, $this->scope]
            : $this->photo;
        expect($outsider->can($ability, $subject))->toBeFalse();
    }

    $inactive = User::factory()->create(['is_active' => false])->assignRole(LogisticsRole::SuperAdmin->value);
    $inactive->jobSites()->attach($this->site);
    expect($inactive->can('viewAny', [ScopePhoto::class, $this->scope]))->toBeFalse()
        ->and($inactive->can('delete', $this->photo))->toBeFalse();
});
