<?php

namespace App\Http\Controllers;

use App\Enums\LogisticsPermission;
use App\Enums\ShiftStatus;
use App\Models\Attendance;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OperationsDashboardController
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Shift::class);

        $user = $request->user();
        $canOpenAdmin = $user->canAny([LogisticsPermission::GlobalAccess->value, LogisticsPermission::SuperviseSites->value]);

        return Inertia::render('Dashboard', [
            'timeZone' => config('app.business_timezone'),
            'canOpenAdmin' => $canOpenAdmin,
            'clock' => fn () => $this->clockPanel($user),
            'sites' => $canOpenAdmin
                ? JobSite::visibleTo($user)->orderBy('name')->limit(20)->get(['id', 'name', 'address'])
                : [],
            'shifts' => Shift::visibleTo($user)->where('ends_at', '>=', now())
                ->with('jobSite:id,name')->orderBy('starts_at')->limit(20)
                ->get(['id', 'job_site_id', 'starts_at', 'ends_at']),
            'lists' => MaterialList::visibleTo($user)->where('status', 'pending')
                ->with('jobSite:id,name')->latest('id')->limit(20)
                ->get(['id', 'job_site_id', 'name']),
        ]);
    }

    private function clockPanel(User $user): array
    {
        $open = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)->where('user_id', $user->id)
            ->whereNull('clocked_out_at')
            ->with(['jobSite' => fn ($site) => $site->withoutGlobalScope(LogisticsVisibilityScope::class)->withTrashed()->select('id', 'name')])
            ->first(['id', 'shift_id', 'job_site_id', 'clocked_in_at', 'geofence_flagged']);

        $shifts = $open ? [] : Shift::forUser($user)->where('user_id', $user->id)
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
