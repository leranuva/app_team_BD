<?php

use App\Enums\LogisticsRole;
use App\Enums\MaterialListStatus;
use App\Filament\Resources\MaterialLists\Pages\CreateMaterialList;
use App\Filament\Resources\MaterialLists\Pages\ListMaterialLists;
use App\Filament\Widgets\MaterialListScopeWidget;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->material = Material::factory()->create(['name' => 'Cable']);
});

it('creates a draft and submits it from the table', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateMaterialList::class)
        ->fillForm([
            'job_site_id' => $this->site->id,
            'job_scope_id' => $this->scope->id,
            'title' => 'Cable de losa',
            'items' => [[
                'material_id' => $this->material->id,
                'requested_quantity' => 3,
                'budgeted_quantity' => 1,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $list = MaterialList::query()->first();
    expect($list->status)->toBe(MaterialListStatus::Draft);

    Livewire::test(ListMaterialLists::class)
        ->callTableAction('submit', $list)
        ->assertNotified('List updated');

    expect($list->fresh()->status)->toBe(MaterialListStatus::Submitted);
});

it('renders the scope summary', function (): void {
    MaterialList::factory()->create([
        'job_site_id' => $this->site->id,
        'job_scope_id' => $this->scope->id,
        'status' => MaterialListStatus::Submitted,
    ]);

    $this->actingAs($this->admin);

    Livewire::test(MaterialListScopeWidget::class, ['record' => $this->scope])
        ->assertSee('Material lists')
        ->assertSee('Awaiting approval')
        ->assertSee('no recorded costs');
});
