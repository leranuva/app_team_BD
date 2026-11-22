<?php

use App\Enums\LogisticsRole;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use App\Services\ChangeOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Notification::fake();
    $this->seed();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->worker = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $this->service = app(ChangeOrderService::class);
    $this->order = $this->service->create($this->supervisor, $this->site, $this->scope, [
        'title' => 'Muro', 'description' => 'Extra', 'amount_cents' => 1000, 'time_impact_days' => 1,
    ]);
});

it('lists and shows an order to the site supervisor', function (): void {
    $this->actingAs($this->supervisor)
        ->getJson('/api/change-orders?job_scope_id='.$this->scope->id)
        ->assertOk()
        ->assertJsonPath('data.0.code', $this->order->code)
        ->assertJsonPath('data.0.can_submit', true)
        ->assertJsonPath('data.0.amount_dollars', 10);

    $this->actingAs($this->worker)->getJson('/api/change-orders/'.$this->order->id)->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/change-orders/999999')->assertNotFound();
});

it('returns 422 for an impossible transition and 403 when the amount exceeds the supervisor', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/change-orders/'.$this->order->id.'/approve', ['note' => 'No'])
        ->assertStatus(422)
        ->assertJsonPath('from', 'draft');

    $review = ChangeOrder::factory()->underReview()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'amount_cents' => 600000,
        'requested_by' => $this->supervisor->id,
    ]);

    $this->actingAs($this->supervisor)
        ->postJson('/api/change-orders/'.$review->id.'/approve')
        ->assertForbidden();

    $this->actingAs($this->supervisor)
        ->postJson('/api/change-orders/'.$this->order->id.'/submit')
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    $this->actingAs($this->admin)
        ->postJson('/api/change-orders/'.$this->order->id.'/reject', [])
        ->assertStatus(422);
});

it('renders the inertia pages', function (): void {
    $this->withoutVite();
    $this->actingAs($this->supervisor)
        ->get('/change-orders?status=draft')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ChangeOrders/Index')->has('orders', 1)->where('canCreate', true));

    $this->actingAs($this->supervisor)
        ->get('/change-orders/'.$this->order->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ChangeOrders/Show')->where('order.can_submit', true));
});
