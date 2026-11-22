<?php

use App\Enums\LogisticsRole;
use App\Filament\Resources\ChangeOrders\Pages\CreateChangeOrder;
use App\Filament\Resources\ChangeOrders\Pages\ListChangeOrders;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('s3');
    $this->site = JobSite::factory()->create(['name' => 'Brooklyn Heights']);
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Encofrado', 'status' => 'active']);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
});

it('creates a draft with an attachment and submits it from the table', function (): void {
    $this->actingAs($this->supervisor);

    Livewire::test(CreateChangeOrder::class)
        ->fillForm([
            'job_site_id' => $this->site->id,
            'job_scope_id' => $this->scope->id,
            'title' => 'Refuerzo',
            'description' => 'Acero adicional',
            'amount_cents' => 180000,
            'time_impact_days' => 2,
            'uploads' => [UploadedFile::fake()->create('orden.pdf', 30, 'application/pdf')],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = ChangeOrder::query()->firstOrFail();
    expect($order->status->value)->toBe('draft')
        ->and($order->attachments()->count())->toBe(1)
        ->and($order->amount_cents)->toBe(180000);

    Livewire::test(ListChangeOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->callTableAction('submit', $order);

    expect($order->refresh()->status->value)->toBe('submitted');

    $this->actingAs($this->admin);
    Livewire::test(ListChangeOrders::class)
        ->callTableAction('cancel', $order, ['reason' => 'Duplicada']);

    expect($order->refresh()->status->value)->toBe('cancelled')
        ->and($order->cancelled_reason)->toBe('Duplicada');
});
