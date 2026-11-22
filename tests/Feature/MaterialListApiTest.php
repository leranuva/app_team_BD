<?php

use App\Enums\LogisticsRole;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\User;
use App\Services\MaterialListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->seed();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->material = Material::factory()->create();
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->carpenter = User::factory()->create()->assignRole(LogisticsRole::Carpenter->value);
    $this->service = app(MaterialListService::class);
});

it('creates, filters and rejects a carpenter', function (): void {
    $this->actingAs($this->supervisor)->postJson('/api/material-lists', [
        'job_scope_id' => $this->scope->id,
        'title' => 'Cable',
        'items' => [['material_id' => $this->material->id, 'requested_quantity' => 1, 'unit_cost_cents' => 500]],
    ])->assertCreated()->assertJsonPath('data.status', 'draft');

    $this->actingAs($this->carpenter)->getJson('/api/material-lists')->assertForbidden();
    $this->actingAs($this->carpenter)->postJson('/api/material-lists', [
        'job_scope_id' => $this->scope->id,
        'title' => 'No',
        'items' => [['material_id' => $this->material->id, 'requested_quantity' => 1]],
    ])->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/material-lists?job_scope_id='.$this->scope->id)
        ->assertOk()->assertJsonPath('data.0.title', 'Cable');
    $this->actingAs($this->admin)->getJson('/api/material-lists/999999')->assertNotFound();
});

it('returns 422 for an invalid transition and for a quantity over the approval', function (): void {
    $list = $this->service->create($this->supervisor, $this->scope, [
        'title' => 'Cable',
        'items' => [['material_id' => $this->material->id, 'requested_quantity' => 1, 'unit_cost_cents' => 100]],
    ]);

    $this->actingAs($this->supervisor)->postJson('/api/material-lists/'.$list->id.'/approve')
        ->assertUnprocessable()->assertJsonPath('from', 'draft');

    $list = $this->service->submit($list, $this->supervisor);
    $list = $this->service->approve($list, $this->supervisor);
    $list = $this->service->markOrdered($list, $this->supervisor);
    $item = $list->items->first();

    $this->actingAs($this->supervisor)->postJson('/api/material-lists/'.$list->id.'/register-delivery', [
        'delivered' => [$item->id => 5],
    ])->assertUnprocessable()->assertJsonValidationErrors('delivered_quantity');

    $this->actingAs($this->admin)->postJson('/api/material-lists/'.$list->id.'/approve')
        ->assertUnprocessable()->assertJsonPath('from', 'ordered')->assertJsonPath('to', 'approved');
});

it('blocks a supervisor above the threshold and lets the admin through', function (): void {
    $list = $this->service->create($this->supervisor, $this->scope, [
        'title' => 'Acero',
        'items' => [['material_id' => $this->material->id, 'requested_quantity' => 1, 'unit_cost_cents' => 250001]],
    ]);
    $list = $this->service->submit($list, $this->supervisor);

    $this->actingAs($this->supervisor)->postJson('/api/material-lists/'.$list->id.'/approve')->assertForbidden();
    $this->actingAs($this->admin)->postJson('/api/material-lists/'.$list->id.'/approve', [
        'approved' => [$list->items->first()->id => 1],
    ])->assertOk()->assertJsonPath('data.status', 'approved');
});
