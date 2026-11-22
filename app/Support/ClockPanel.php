<?php

namespace App\Support;

use App\Enums\ShiftStatus;
use App\Models\Attendance;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ClockPanel
{
    /**
     * @return array{open: Attendance|null, shifts: Collection<int, array<string, mixed>>, sites: Collection<int, array<string, mixed>>}
     */
    public function for(User $user): array
    {
        $open = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)->where('user_id', $user->id)
            ->whereNull('clocked_out_at')
            ->with(['jobSite' => fn ($site) => $site->withoutGlobalScope(LogisticsVisibilityScope::class)->withTrashed()->select('id', 'name')])
            ->first(['id', 'shift_id', 'job_site_id', 'clocked_in_at', 'geofence_flagged']);

        $now = CarbonImmutable::now('UTC');
        $window = config('attendance.clock_in_window_minutes');
        $shifts = Shift::forUser($user)->where('user_id', $user->id)
            ->whereIn('status', [ShiftStatus::Programmed->value, ShiftStatus::Confirmed->value])
            ->whereDoesntHave('attendance')
            ->where('ends_at', '>=', $now)
            ->with('jobSite:id,name')->orderBy('starts_at')->limit(10)
            ->get(['id', 'job_site_id', 'starts_at', 'ends_at'])
            ->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'starts_at' => $shift->starts_at->toIso8601String(),
                'available_from' => $window === null ? null : $shift->starts_at->avoidMutation()->subMinutes($window)->toIso8601String(),
                'available_until' => ($window === null
                    ? $shift->ends_at
                    : $shift->starts_at->avoidMutation()->addMinutes($window)->min($shift->ends_at))->toIso8601String(),
                'job_site' => $shift->jobSite?->only(['name']),
            ]);

        $sites = $open || $user->canResumeWorkToday()
            ? $user->clockableJobSites()->orderBy('job_sites.name')->get(['job_sites.id', 'job_sites.name'])
                ->map(fn ($site): array => $site->only(['id', 'name']))
            : collect();

        return ['open' => $open, 'shifts' => $shifts, 'sites' => $sites];
    }
}
