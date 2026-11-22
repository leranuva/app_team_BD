<?php

namespace App\Http\Controllers;

use App\Enums\LogisticsRole;
use App\Enums\ShiftStatus;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SupervisorTeamController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', JobScope::class);

        $actor = $request->user();
        $sites = JobSite::visibleTo($actor)->orderBy('name')->get(['id', 'name']);
        $siteId = $this->siteId($request, $sites);

        return Inertia::render('Supervisor/TeamToday', [
            'timeZone' => config('app.business_timezone'),
            'sites' => $sites->map(fn (JobSite $site): array => $site->only(['id', 'name']))->values()->all(),
            'jobSiteId' => $siteId,
            'team' => fn () => $this->team($actor, $siteId),
        ]);
    }

    /**
     * @param  Collection<int, JobSite>  $sites
     */
    private function siteId(Request $request, $sites): ?int
    {
        $requested = $request->integer('job_site_id');
        if ($requested === 0) {
            return null;
        }

        abort_unless($sites->contains('id', $requested), 403);

        return $requested;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function team(User $actor, ?int $siteId): array
    {
        $zone = config('app.business_timezone');
        $start = CarbonImmutable::now($zone)->startOfDay();
        $end = $start->addDay();

        $workers = User::query()->workersVisibleTo($actor)
            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', [
                LogisticsRole::Carpenter->value,
                LogisticsRole::Helper->value,
            ]))
            ->when($siteId, fn ($query) => $query->whereHas(
                'jobSites',
                fn ($sites) => $sites->whereKey($siteId),
            ))
            ->orderBy('name')
            ->get(['id', 'name']);

        $shifts = Shift::query()->visibleTo($actor)
            ->whereIn('user_id', $workers->modelKeys() ?: [0])
            ->when($siteId, fn ($query) => $query->where('job_site_id', $siteId))
            ->where('starts_at', '<', $end->utc())
            ->where('ends_at', '>', $start->utc())
            ->where('status', '!=', ShiftStatus::Cancelled->value)
            ->with(['attendance', 'jobSite:id,name'])
            ->orderBy('starts_at')
            ->get()
            ->groupBy('user_id');

        return $workers->flatMap(function (User $worker) use ($actor, $shifts, $zone): array {
            $assignments = $shifts->get($worker->id) ?? collect([null]);

            return $assignments->map(function (?Shift $shift) use ($actor, $worker, $zone): array {
                $attendance = $shift?->attendance;

                return [
                    'id' => $worker->id,
                    'shift_id' => $shift?->id,
                    'name' => $worker->name,
                    'status' => match (true) {
                        $attendance?->geofence_flagged => 'outside_geofence',
                        $attendance?->clocked_out_at !== null => 'completed',
                        $attendance !== null => 'clocked_in',
                        $shift !== null => 'scheduled',
                        default => 'no_shift',
                    },
                    'job_site' => $shift?->jobSite?->name,
                    'clocked_in_at' => $attendance?->clocked_in_at?->setTimezone($zone)->toIso8601String(),
                    'clocked_out_at' => $attendance?->clocked_out_at?->setTimezone($zone)->toIso8601String(),
                    'attendance_id' => $attendance?->id,
                    'can_correct' => $attendance !== null && $actor->can('correct', $attendance),
                ];
            })->all();
        })->values()->all();
    }
}
