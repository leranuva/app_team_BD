<?php

namespace App\Services;

use App\Enums\LogisticsRole;
use App\Enums\MaterialListStatus;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Material;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\User;
use App\Notifications\MaterialListApprovedNotification;
use App\Notifications\MaterialListCancelledNotification;
use App\Notifications\MaterialListDeliveredNotification;
use App\Notifications\MaterialListSubmittedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class MaterialListService
{
    public function __construct(private readonly MaterialListStateMachine $machine) {}

    public function create(User $actor, JobScope $scope, array $data): MaterialList
    {
        $scope = $this->scope($scope);
        $site = JobSite::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($scope->job_site_id);
        Gate::forUser($actor)->authorize('create', [MaterialList::class, $site]);
        $payload = validator($data, [
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_id' => ['required', 'integer', 'distinct'],
            'items.*.requested_quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'items.*.budgeted_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.unit_cost_cents' => ['nullable', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $this->assertActiveMaterials($payload['items']);

        return DB::transaction(function () use ($actor, $site, $scope, $payload): MaterialList {
            $list = new MaterialList([
                'job_site_id' => $site->id,
                'job_scope_id' => $scope->id,
                'code' => $this->nextCode($site->id),
                'title' => $payload['title'],
                'name' => $payload['title'],
                'notes' => $payload['notes'] ?? null,
                'status' => MaterialListStatus::Draft,
                'requested_by' => $actor->id,
                'assigned_to' => $actor->id,
            ]);
            $list->recordedBy($actor)->save();
            $this->replaceItems($list, $payload['items']);

            return $list->refresh()->load('items');
        });
    }

    public function updateItems(MaterialList $list, User $actor, array $items): MaterialList
    {
        return $this->update($list, $actor, ['items' => $items]);
    }

    public function update(MaterialList $list, User $actor, array $data): MaterialList
    {
        $record = $this->locked($list);
        Gate::forUser($actor)->authorize('update', $record);
        $payload = validator($data, [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.material_id' => ['required_with:items', 'integer', 'distinct'],
            'items.*.requested_quantity' => ['required_with:items', 'numeric', 'min:0', 'max:999999999'],
            'items.*.budgeted_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.unit_cost_cents' => ['nullable', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        if (isset($payload['items'])) {
            $this->assertActiveMaterials($payload['items']);
        }

        return DB::transaction(function () use ($record, $actor, $payload): MaterialList {
            $locked = $this->locked($record);
            Gate::forUser($actor)->authorize('update', $locked);
            if (array_key_exists('title', $payload)) {
                $locked->title = $payload['title'];
                $locked->name = $payload['title'];
            }
            if (array_key_exists('notes', $payload)) {
                $locked->notes = $payload['notes'];
            }
            $locked->recordedBy($actor)->save();
            if (isset($payload['items'])) {
                $this->replaceItems($locked, $payload['items']);
            }

            return $locked->refresh()->load('items');
        });
    }

    public function submit(MaterialList $list, User $actor): MaterialList
    {
        $saved = $this->transition($list, $actor, MaterialListStatus::Submitted, function (MaterialList $record): void {
            if (! $record->items()->exists()) {
                throw ValidationException::withMessages(['items' => 'La lista necesita al menos un material.']);
            }
        });
        $this->notifySubmitted($saved);

        return $saved;
    }

    public function approve(MaterialList $list, User $actor, array $approvedQuantities = []): MaterialList
    {
        $saved = $this->transition($list, $actor, MaterialListStatus::Approved, function (MaterialList $record, User $actor) use ($approvedQuantities): void {
            foreach ($record->items as $item) {
                $quantity = $approvedQuantities[$item->id] ?? $approvedQuantities[(string) $item->id] ?? $item->requested_quantity;
                $this->assertQuantity($quantity, 'approved_quantity');
                $item->forceFill(['approved_quantity' => $this->decimal($quantity)])->save();
            }
            $record->forceFill(['approved_by' => $actor->id])->recordedBy($actor)->save();
        });
        $this->notify($saved, new MaterialListApprovedNotification($saved->id, (string) $saved->code, (string) $saved->title), collect([$saved->requestedBy])->filter());

        return $saved;
    }

    public function markOrdered(MaterialList $list, User $actor): MaterialList
    {
        return $this->transition($list, $actor, MaterialListStatus::Ordered, function (MaterialList $record, User $actor): void {
            $record->forceFill(['ordered_at' => now()])->recordedBy($actor)->save();
        });
    }

    public function registerDelivery(MaterialList $list, User $actor, array $deliveredQuantities, bool $confirm = false): MaterialList
    {
        $saved = $this->transition($list, $actor, MaterialListStatus::Delivered, function (MaterialList $record) use ($deliveredQuantities, $confirm): void {
            $this->applyQuantities($record, $deliveredQuantities, 'delivered_quantity', 'approved_quantity', $confirm);
            $record->forceFill(['delivered_at' => now()])->save();
        });
        $this->notify($saved, new MaterialListDeliveredNotification($saved->id, (string) $saved->code, (string) $saved->title), $this->requesterAndAdmins($saved));

        return $saved;
    }

    public function registerConsumption(MaterialList $list, User $actor, array $consumedQuantities, bool $confirm = false): MaterialList
    {
        return DB::transaction(function () use ($list, $actor, $consumedQuantities, $confirm): MaterialList {
            $record = $this->locked($list);
            Gate::forUser($actor)->authorize('registerConsumption', $record);
            $record->load('items');
            $this->applyQuantities($record, $consumedQuantities, 'consumed_quantity', 'delivered_quantity', $confirm);
            $record->refresh()->load('items');
            $to = $record->allItemsConsumed()
                ? MaterialListStatus::Consumed
                : ($this->anyConsumption($record) ? MaterialListStatus::PartiallyConsumed : null);
            if ($to !== null && $to !== $record->status) {
                $this->machine->assertAvailable($record->status, $to);
                if (! $this->machine->actorMay($record->status, $to, $actor, $record)) {
                    throw new AuthorizationException('No puedes registrar este consumo.');
                }
                $record->status = $to;
                if ($to === MaterialListStatus::Consumed) {
                    $record->consumed_at = now();
                }
                $record->recordedBy($actor)->save();
            }

            return $record->refresh()->load('items');
        });
    }

    public function cancel(MaterialList $list, User $actor, string $reason): MaterialList
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo.']);
        }

        $saved = $this->transition($list, $actor, MaterialListStatus::Cancelled, function (MaterialList $record, User $actor) use ($reason): void {
            $record->forceFill(['cancelled_reason' => $reason])->recordedBy($actor, $reason)->save();
        });
        $this->notify($saved, new MaterialListCancelledNotification($saved->id, (string) $saved->code, (string) $saved->title, $reason), $this->requesterAndAdmins($saved));

        return $saved;
    }

    private function transition(MaterialList $list, User $actor, MaterialListStatus $to, callable $apply): MaterialList
    {
        return DB::transaction(function () use ($list, $actor, $to, $apply): MaterialList {
            $record = $this->locked($list)->load('items');
            $this->machine->assertAvailable($record->status, $to);
            $ability = match ($to) {
                MaterialListStatus::Submitted => 'submit',
                MaterialListStatus::Approved => 'approve',
                MaterialListStatus::Ordered => 'markOrdered',
                MaterialListStatus::Delivered => 'registerDelivery',
                MaterialListStatus::Cancelled => 'cancel',
                default => 'view',
            };
            Gate::forUser($actor)->authorize($ability, $record);
            $record->status = $to;
            $apply($record, $actor);
            if ($record->isDirty()) {
                $record->recordedBy($actor)->save();
            }

            return $record->refresh()->load('items');
        });
    }

    private function locked(MaterialList $list): MaterialList
    {
        return MaterialList::withoutGlobalScope(LogisticsVisibilityScope::class)->lockForUpdate()->findOrFail($list->getKey());
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceItems(MaterialList $list, array $items): void
    {
        $list->items()->delete();
        foreach ($items as $item) {
            $list->items()->create([
                'material_id' => $item['material_id'],
                'budgeted_quantity' => $this->decimal($item['budgeted_quantity'] ?? 0),
                'requested_quantity' => $this->decimal($item['requested_quantity']),
                'unit_cost_cents' => $item['unit_cost_cents'] ?? null,
                'notes' => $item['notes'] ?? null,
                'used_quantity' => 0,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function assertActiveMaterials(array $items): void
    {
        $ids = array_map(fn (array $item): int => (int) $item['material_id'], $items);
        $active = Material::query()->whereIn('id', $ids)->where('is_active', true)->pluck('id');
        if ($active->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'Cada material debe existir y estar activo.']);
        }
    }

    /**
     * @param  array<int|string, mixed>  $quantities
     */
    private function applyQuantities(MaterialList $list, array $quantities, string $column, string $limitColumn, bool $confirm): void
    {
        if ($quantities === []) {
            throw ValidationException::withMessages([$column => 'Indica las cantidades.']);
        }

        foreach ($list->items as $item) {
            if (! array_key_exists($item->id, $quantities) && ! array_key_exists((string) $item->id, $quantities)) {
                throw ValidationException::withMessages([$column => 'Falta la cantidad de un material.']);
            }
            $quantity = $quantities[$item->id] ?? $quantities[(string) $item->id];
            $this->assertQuantity($quantity, $column);
            $limit = $item->{$limitColumn} ?? $item->requested_quantity ?? '0';
            if (! $confirm && bccomp($this->decimal($quantity), $this->decimal($limit), 3) > 0) {
                throw ValidationException::withMessages([
                    $column => 'La cantidad supera lo permitido. Confirma para registrarla igual.',
                ]);
            }
            $item->forceFill([$column => $this->decimal($quantity)])->save();
        }
    }

    private function assertQuantity(mixed $quantity, string $field): void
    {
        if (! is_numeric($quantity) || (float) $quantity < 0) {
            throw ValidationException::withMessages([$field => 'La cantidad no puede ser negativa.']);
        }
    }

    private function anyConsumption(MaterialList $list): bool
    {
        return $list->items->contains(fn (MaterialListItem $item): bool => $item->consumed_quantity !== null && bccomp($this->decimal($item->consumed_quantity), '0.000', 3) > 0);
    }

    private function nextCode(int $siteId): string
    {
        $year = (int) now(config('app.business_timezone'))->year;
        $key = ['job_site_id' => $siteId, 'year' => $year];
        DB::table('material_list_counters')->insertOrIgnore($key + ['last_seq' => 0]);
        $current = (int) DB::table('material_list_counters')->where($key)->lockForUpdate()->value('last_seq');
        $next = $current + 1;
        DB::table('material_list_counters')->where($key)->update(['last_seq' => $next]);

        return sprintf('%s-%d-%d-%04d', config('material_lists.code_prefix'), $siteId, $year, $next);
    }

    private function scope(JobScope $scope): JobScope
    {
        return JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($scope->getKey());
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function notifySubmitted(MaterialList $list): void
    {
        $admins = $this->admins();
        $supervisors = $list->jobSite?->users()->where('is_active', true)->get()->filter(
            fn (User $user): bool => $user->can('logistics.sites.supervise') && (int) $user->id !== (int) $list->requested_by,
        ) ?? collect();
        $this->notify($list, new MaterialListSubmittedNotification($list->id, (string) $list->code, (string) $list->title), $admins->merge($supervisors)->unique('id'));
    }

    private function requesterAndAdmins(MaterialList $list): Collection
    {
        return $this->admins()->push($list->requestedBy)->filter()->unique('id');
    }

    private function admins(): Collection
    {
        return User::query()->role([LogisticsRole::SuperAdmin->value, LogisticsRole::Administrator->value])->where('is_active', true)->get();
    }

    private function notify(MaterialList $list, \Illuminate\Notifications\Notification $notification, Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, $notification);
    }
}
