<?php

namespace App\Actions;

use App\Exceptions\ShiftConcurrencyException;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftReassignedNotification;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReassignShiftAction
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function __invoke(Shift $shift, CarbonImmutable $startAt, CarbonImmutable $endAt, CarbonImmutable $expectedUpdatedAt, User $actor): Shift
    {
        $start = $startAt->utc()->startOfSecond();
        $end = $endAt->utc()->startOfSecond();

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['end_at' => 'The end time must be after the start time, to the second.']);
        }

        [$record, $previousStart, $previousEnd] = DB::transaction(function () use ($actor, $shift, $start, $end, $expectedUpdatedAt): array {
            // Keep the same worker-before-shift lock order used by Filament.
            User::query()->lockForUpdate()->find($shift->user_id);
            $record = Shift::withoutGlobalScope(LogisticsVisibilityScope::class)->lockForUpdate()->findOrFail($shift->id);
            Gate::forUser($actor)->authorize('update', $record);
            if ($record->user_id !== $shift->user_id || ! $record->updated_at?->equalTo($expectedUpdatedAt)) {
                throw new ShiftConcurrencyException($record);
            }

            $previousStart = $record->starts_at;
            $previousEnd = $record->ends_at;
            $record->fill(['starts_at' => $start, 'ends_at' => $end]);

            if (! $record->isDirty(['starts_at', 'ends_at'])) {
                return [$record, null, null];
            }

            $this->shifts->ensureNoOverlap($record->user_id, $start, $end, $record->id, 'start_at');
            $record->forceFill(['updated_by' => $actor->id])->save();

            return [$record->refresh(), $previousStart, $previousEnd];
        }, attempts: 3);

        if ($previousStart !== null && $record->user_id !== $actor->id) {
            $timezone = config('app.business_timezone');
            $record->worker?->notify(new ShiftReassignedNotification(
                $record->id,
                $record->jobSite?->name ?? '',
                $previousStart->setTimezone($timezone)->toIso8601String(),
                $previousEnd->setTimezone($timezone)->toIso8601String(),
                $start->setTimezone($timezone)->toIso8601String(),
                $end->setTimezone($timezone)->toIso8601String(),
                $actor->name,
            ));
        }

        return $record;
    }
}
