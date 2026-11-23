<?php

namespace Tests\Feature;

use App\Models\JobSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_profile_lists_only_the_users_assigned_sites_without_requiring_shifts(): void
    {
        $primary = JobSite::factory()->create(['name' => 'Brooklyn']);
        $secondary = JobSite::factory()->create(['name' => 'Queens']);
        JobSite::factory()->create(['name' => 'Unassigned']);
        $user = User::factory()->create(['primary_job_site_id' => $primary->id]);
        $user->jobSites()->attach($secondary);

        $this->actingAs($user)->get('/profile')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('Profile/Edit')
                ->where('primaryJobSiteId', $primary->id)
                ->has('jobSites', 2)
                ->where('jobSites.0.id', $primary->id)
                ->where('jobSites.1.id', $secondary->id),
        );
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_workers_cannot_delete_their_employment_record(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response->assertMethodNotAllowed();
        $this->assertNotNull($user->fresh());
    }
}
