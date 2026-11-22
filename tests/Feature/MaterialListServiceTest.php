<?php

use App\Enums\LogisticsRole;
use App\Enums\MaterialListStatus;
use App\Exceptions\InvalidMaterialListTransitionException;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\User;
use App\Notifications\MaterialListApprovedNotification;
use App\Notifications\MaterialListSubmittedNotification;
use App\Services\MaterialListService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    Notification::fake();
    $this->site = JobSite::factory()->create();
    $this->scope = JobScope::query()->create(['job_site_id' => $this->site->id, 'name' => 'Losa']);
    $this->material = Material::factory()->create(['is_active' => true]);
    $this->admin = User::factory()->create()->assignRole(LogisticsRole::Administrator->value);
    $this->supervisor = User::factory()->create()->assignRole(LogisticsRole::Supervisor->value);
    $this->supervisor->jobSites()->attach($this->site);
    $this->service = app(MaterialListService::class);
});

function listPayload(int $materialId, float $quantity = 2, ?int $cost = 1000): array
{
    return [
        'title' => 'Tornillos de losa',
        'items' => [[
            'material_id' => $materialId,
            'requested_quantity' => $quantity,
            'budgeted_quantity' => 1,
            'unit_cost_cents' => $cost,
        ]],
    ];
}

it('walks a list from draft to consumed and keeps the code sequence', function (): void {
    $first = $this->service->create($this->supervisor, $this->scope, listPayload($this->material->id));
    $second = $this->service->create($this->supervisor, $this->scope, listPayload($this->material->id, 1));
    $year = now(config('app.business_timezone'))->year;

    expect($first->code)->toBe(sprintf('ML-%d-%d-0001', $this->site->id, $year))
        ->and($second->code)->toBe(sprintf('ML-%d-%d-0002', $this->site->id, $year))
        ->and($first->status)->toBe(MaterialListStatus::Draft)
        ->and($first->isEditable())->toBeTrue();

    $submitted = $this->service->submit($first, $this->supervisor);
    Notification::assertSentTo($this->admin, MaterialListSubmittedNotification::class);
    $approved = $this->service->approve($submitted, $this->admin, [$submitted->items->first()->id => 2]);
    Notification::assertSentTo($this->supervisor, MaterialListApprovedNotification::class);
    expect($approved->items->first()->approved_quantity)->toBe('2.000');

    $ordered = $this->service->markOrdered($approved, $this->supervisor);
    expect($ordered->ordered_at)->not->toBeNull();
    $delivered = $this->service->registerDelivery($ordered, $this->supervisor, [$ordered->items->first()->id => 2]);
    expect($delivered->status)->toBe(MaterialListStatus::Delivered)
        ->and($delivered->allItemsConsumed())->toBeFalse();

    $partial = $this->service->registerConsumption($delivered, $this->supervisor, [$delivered->items->first()->id => 1]);
    expect($partial->status)->toBe(MaterialListStatus::PartiallyConsumed);

    $consumed = $this->service->registerConsumption($partial, $this->supervisor, [$partial->items->first()->id => 2]);
    expect($consumed->status)->toBe(MaterialListStatus::Consumed)
        ->and($consumed->consumed_at)->not->toBeNull();

    expect(fn () => $this->service->cancel($consumed, $this->admin, 'tarde'))
        ->toThrow(InvalidMaterialListTransitionException::class);
});

it('consumes a delivery in one step and rejects a negative or unconfirmed excess', function (): void {
    $list = $this->service->create($this->supervisor, $this->scope, listPayload($this->material->id));
    $list = $this->service->submit($list, $this->supervisor);
    $list = $this->service->approve($list, $this->supervisor);
    $list = $this->service->markOrdered($list, $this->supervisor);
    $itemId = $list->items->first()->id;

    expect(fn () => $this->service->registerDelivery($list, $this->supervisor, [$itemId => -1]))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->service->registerDelivery($list, $this->supervisor, [$itemId => 3]))
        ->toThrow(ValidationException::class);

    $delivered = $this->service->registerDelivery($list, $this->supervisor, [$itemId => 3], true);
    $consumed = $this->service->registerConsumption($delivered, $this->supervisor, [$itemId => 3]);

    expect($consumed->status)->toBe(MaterialListStatus::Consumed)
        ->and($consumed->items->first()->consumed_quantity)->toBe('3.000');
});

it('lets a supervisor approve only under the threshold', function (): void {
    $cheap = $this->service->create($this->supervisor, $this->scope, listPayload($this->material->id, 1, 250000));
    $cheap = $this->service->submit($cheap, $this->supervisor);
    expect($this->service->approve($cheap, $this->supervisor)->status)->toBe(MaterialListStatus::Approved);

    $costly = $this->service->create($this->supervisor, $this->scope, listPayload($this->material->id, 1, 250001));
    $costly = $this->service->submit($costly, $this->supervisor);
    expect(fn () => $this->service->approve($costly, $this->supervisor))->toThrow(AuthorizationException::class);
    expect($this->service->approve($costly, $this->admin)->status)->toBe(MaterialListStatus::Approved);
});

it('rejects an inactive material and keeps the total null without a cost', function (): void {
    $inactive = Material::factory()->inactive()->create();
    expect(fn () => $this->service->create($this->supervisor, $this->scope, listPayload($inactive->id)))
        ->toThrow(ValidationException::class);

    $list = $this->service->create($this->supervisor, $this->scope, listPayload($this->material->id, 2, null));
    expect($list->totalRequestedValueCents())->toBeNull()
        ->and($list->items->first()->pendingDelivery())->toBe('2.000');
});
