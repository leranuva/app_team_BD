<?php

namespace App\Services;

use App\Enums\ChangeOrderStatus;
use App\Enums\LogisticsRole;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\User;
use App\Notifications\ChangeOrderApprovedNotification;
use App\Notifications\ChangeOrderRejectedNotification;
use App\Notifications\ChangeOrderReviewedNotification;
use App\Notifications\ChangeOrderSubmittedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ChangeOrderService
{
    public function __construct(private readonly ChangeOrderStateMachine $machine) {}

    public function create(User $actor, JobSite $site, ?JobScope $scope, array $data): ChangeOrder
    {
        $site = JobSite::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($site->getKey());
        Gate::forUser($actor)->authorize('create', [ChangeOrder::class, $site]);
        $scope = $this->scopeOnSite($site, $scope);
        $payload = validator($data, [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'amount_cents' => ['required', 'integer', 'between:-100000000000,100000000000'],
            'time_impact_days' => ['required', 'integer', 'between:-3650,3650'],
            'schedule_impact_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $site, $scope, $payload): ChangeOrder {
            $order = new ChangeOrder($payload + [
                'job_site_id' => $site->id,
                'job_scope_id' => $scope?->id,
                'code' => $this->nextCode($site->id),
                'status' => ChangeOrderStatus::Draft,
                'currency' => 'USD',
                'created_by' => $actor->id,
                'requested_by' => $actor->id,
            ]);
            $order->recordedBy($actor)->save();

            return $order->refresh();
        });
    }

    public function submit(ChangeOrder $order, User $actor): ChangeOrder
    {
        $saved = $this->transition($order, $actor, ChangeOrderStatus::Submitted, function (ChangeOrder $record, User $actor): void {
            $record->forceFill(['submitted_at' => now()])->recordedBy($actor)->save();
        });
        $this->notify($saved, new ChangeOrderSubmittedNotification($saved->id, $this->label($saved), (string) $saved->title), 'reviewers');

        return $saved;
    }

    public function startReview(ChangeOrder $order, User $actor): ChangeOrder
    {
        $saved = $this->transition($order, $actor, ChangeOrderStatus::UnderReview, function (ChangeOrder $record, User $actor): void {
            $record->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now()])->recordedBy($actor)->save();
        });
        $this->notify($saved, new ChangeOrderReviewedNotification($saved->id, $this->label($saved), (string) $saved->title), 'requester');

        return $saved;
    }

    public function approve(ChangeOrder $order, User $actor, ?string $note = null): ChangeOrder
    {
        $note = $this->optionalNote($note);
        $saved = $this->transition($order, $actor, ChangeOrderStatus::Approved, function (ChangeOrder $record, User $actor) use ($note): void {
            $record->forceFill([
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'applied_to_scope_at' => now(),
            ])->recordedBy($actor, $note)->save();
            $this->recalculateImpact($record->jobSite()->withoutGlobalScope(LogisticsVisibilityScope::class)->firstOrFail());
        });
        $this->notify($saved, new ChangeOrderApprovedNotification($saved->id, $this->label($saved), (string) $saved->title), 'requester-and-supervisors');

        return $saved;
    }

    public function reject(ChangeOrder $order, User $actor, string $reason): ChangeOrder
    {
        $reason = $this->requiredReason($reason, 'rejection_reason');
        $saved = $this->transition($order, $actor, ChangeOrderStatus::Rejected, function (ChangeOrder $record, User $actor) use ($reason): void {
            $record->forceFill([
                'reviewed_by' => $record->reviewed_by ?? $actor->id,
                'reviewed_at' => $record->reviewed_at ?? now(),
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->recordedBy($actor, $reason)->save();
        });
        $this->notify($saved, new ChangeOrderRejectedNotification($saved->id, $this->label($saved), (string) $saved->title, $reason), 'requester');

        return $saved;
    }

    public function cancel(ChangeOrder $order, User $actor, string $reason): ChangeOrder
    {
        $reason = $this->requiredReason($reason, 'cancelled_reason');

        return $this->transition($order, $actor, ChangeOrderStatus::Cancelled, function (ChangeOrder $record, User $actor) use ($reason): void {
            $record->forceFill(['cancelled_reason' => $reason])->recordedBy($actor, $reason)->save();
        });
    }

    public function recalculateImpact(JobSite $site): void
    {
        $approved = ChangeOrder::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->where('job_site_id', $site->id)
            ->where('status', ChangeOrderStatus::Approved)
            ->get(['job_scope_id', 'amount_cents', 'time_impact_days']);

        JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->where('job_site_id', $site->id)
            ->update(['approved_change_orders_cents' => 0, 'approved_time_impact_days' => 0]);

        $siteCents = 0;
        $siteDays = 0;
        $byScope = [];
        foreach ($approved as $order) {
            if ($order->job_scope_id === null) {
                $siteCents += $order->amount_cents;
                $siteDays += $order->time_impact_days;

                continue;
            }
            $byScope[$order->job_scope_id]['cents'] = ($byScope[$order->job_scope_id]['cents'] ?? 0) + $order->amount_cents;
            $byScope[$order->job_scope_id]['days'] = ($byScope[$order->job_scope_id]['days'] ?? 0) + $order->time_impact_days;
        }

        foreach ($byScope as $scopeId => $impact) {
            JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->whereKey($scopeId)->update([
                'approved_change_orders_cents' => $impact['cents'],
                'approved_time_impact_days' => $impact['days'],
            ]);
        }

        $site->newQuery()->withoutGlobalScope(LogisticsVisibilityScope::class)->whereKey($site->id)->update([
            'approved_change_orders_cents' => $siteCents,
            'approved_time_impact_days' => $siteDays,
        ]);
    }

    private function transition(ChangeOrder $order, User $actor, ChangeOrderStatus $to, callable $apply): ChangeOrder
    {
        return DB::transaction(function () use ($order, $actor, $to, $apply): ChangeOrder {
            $record = ChangeOrder::withoutGlobalScope(LogisticsVisibilityScope::class)->lockForUpdate()->findOrFail($order->getKey());
            Gate::forUser($actor)->authorize('view', $record);
            $this->machine->assertAvailable($record->status, $to);
            Gate::forUser($actor)->authorize($this->ability($to), $record);
            $record->status = $to;
            $apply($record, $actor);

            return $record->refresh();
        });
    }

    private function ability(ChangeOrderStatus $to): string
    {
        return match ($to) {
            ChangeOrderStatus::Submitted => 'submit',
            ChangeOrderStatus::UnderReview => 'startReview',
            ChangeOrderStatus::Approved => 'approve',
            ChangeOrderStatus::Rejected => 'reject',
            ChangeOrderStatus::Cancelled => 'cancel',
            default => 'update',
        };
    }

    private function nextCode(int $siteId): string
    {
        $year = (int) now(config('app.business_timezone'))->year;
        $key = ['job_site_id' => $siteId, 'year' => $year];
        DB::table('change_order_counters')->insertOrIgnore($key + ['last_seq' => 0]);
        $current = (int) DB::table('change_order_counters')->where($key)->lockForUpdate()->value('last_seq');
        $next = $current + 1;
        DB::table('change_order_counters')->where($key)->update(['last_seq' => $next]);

        return sprintf('%s-%d-%d-%04d', config('change_orders.code_prefix'), $siteId, $year, $next);
    }

    private function scopeOnSite(JobSite $site, ?JobScope $scope): ?JobScope
    {
        if ($scope === null) {
            return null;
        }

        $found = JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($scope->getKey());
        if ((int) $found->job_site_id !== (int) $site->id) {
            throw ValidationException::withMessages(['job_scope_id' => 'El alcance no pertenece a la obra.']);
        }

        return $found;
    }

    private function requiredReason(string $reason, string $key): string
    {
        $reason = trim($reason);
        validator([$key => $reason], [$key => ['required', 'string', 'max:2000']])->validate();

        return $reason;
    }

    private function optionalNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : validator(['note' => $note], ['note' => ['string', 'max:2000']])->validate()['note'];
    }

    private function label(ChangeOrder $order): string
    {
        return $order->code ?? 'CO-'.$order->id;
    }

    private function notify(ChangeOrder $order, object $notification, string $audience): void
    {
        $order->loadMissing('jobSite.supervisors', 'requestedBy');
        $supervisors = $order->jobSite->supervisors->where('is_active', true);
        $recipients = match ($audience) {
            'requester' => collect([$order->requestedBy])->filter(),
            'requester-and-supervisors' => $supervisors->merge([$order->requestedBy])->filter(),
            default => $supervisors->merge(
                User::query()->role([LogisticsRole::Administrator->value, LogisticsRole::SuperAdmin->value])->where('is_active', true)->get(),
            ),
        };

        $recipients = $recipients->unique('id')->values();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }
}
