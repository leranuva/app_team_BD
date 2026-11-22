<?php

namespace App\Support;

use App\Enums\ShiftStatus;
use App\Models\Attendance;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;

class ClockPanel
{
    /**
     * @return array{open: Attendance|null, shifts: Collection<int, Shift>}
     */
    public function for(User $user): array
    {
        $open = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)->where('user_id', $user->id)
            ->whereNull('clocked_out_at')
            ->with(['jobSite' => fn ($site) => $site->withoutGlobalScope(LogisticsVisibilityScope::class)->withTrashed()->select('id', 'name')])
            ->first(['id', 'shift_id', 'job_site_id', 'clocked_in_at', 'geofence_flagged']);

        $shifts = $open ? collect() : Shift::forUser($user)->where('user_id', $user->id)
            ->whereIn('status', [ShiftStatus::Programmed->value, ShiftStatus::Confirmed->value])
            ->whereDoesntHave('attendance')
            ->where('ends_at', '>=', now())
            ->when(config('attendance.clock_in_window_minutes') !== null, fn ($query) => $query
                ->where('starts_at', '>=', now()->subMinutes(config('attendance.clock_in_window_minutes'))))
            ->with('jobSite:id,name')->orderBy('starts_at')->limit(10)
            ->get(['id', 'job_site_id', 'starts_at']);

        return ['open' => $open, 'shifts' => $shifts];
    }
}
