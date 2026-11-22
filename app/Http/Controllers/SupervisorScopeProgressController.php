<?php

namespace App\Http\Controllers;

use App\Enums\MaterialListStatus;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\ScopePhoto;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SupervisorScopeProgressController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', JobScope::class);

        $actor = $request->user();
        $sites = JobSite::visibleTo($actor)->orderBy('name')->get(['id', 'name']);
        $requested = $request->integer('job_site_id');
        $siteId = $requested === 0 ? null : $requested;
        if ($siteId !== null) {
            abort_unless($sites->contains('id', $siteId), 403);
        }

        return Inertia::render('Supervisor/ScopeProgress', [
            'timeZone' => config('app.business_timezone'),
            'sites' => $sites->map(fn (JobSite $site): array => $site->only(['id', 'name']))->values()->all(),
            'jobSiteId' => $siteId,
            'scopes' => $this->scopes($actor, $siteId),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scopes($actor, ?int $siteId): array
    {
        $zone = config('app.business_timezone');
        $start = CarbonImmutable::now($zone)->startOfDay();
        $end = $start->addDay();

        $scopes = JobScope::visibleTo($actor)
            ->with(['jobSite:id,name', 'supervisor:id,name'])
            ->when($siteId, fn ($query) => $query->where('job_site_id', $siteId))
            ->orderBy('name')
            ->limit(50)
            ->get();

        $ids = $scopes->modelKeys();
        $today = Shift::query()->visibleTo($actor)
            ->whereIn('job_scope_id', $ids ?: [0])
            ->where('starts_at', '<', $end->utc())
            ->where('ends_at', '>', $start->utc())
            ->where('status', '!=', 'cancelled')
            ->with('worker:id,name')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('job_scope_id');

        $orders = ChangeOrder::query()->visibleTo($actor)->pendingReview()
            ->whereIn('job_scope_id', $ids ?: [0])
            ->selectRaw('job_scope_id, count(*) as aggregate')
            ->groupBy('job_scope_id')
            ->pluck('aggregate', 'job_scope_id');

        $lists = MaterialList::query()->visibleTo($actor)
            ->whereIn('status', [MaterialListStatus::Pending->value, MaterialListStatus::Submitted->value])
            ->whereIn('job_scope_id', $ids ?: [0])
            ->selectRaw('job_scope_id, count(*) as aggregate')
            ->groupBy('job_scope_id')
            ->pluck('aggregate', 'job_scope_id');

        return $scopes->map(function (JobScope $scope) use ($actor, $today, $orders, $lists, $zone): array {
            $photos = ScopePhoto::query()->active()
                ->where('job_scope_id', $scope->id)
                ->latest('created_at')
                ->latest('id')
                ->limit(3)
                ->get()
                ->map(fn (ScopePhoto $photo): array => [
                    'id' => $photo->id,
                    'caption' => $photo->caption,
                    'url' => $photo->temporaryUrl(),
                ])
                ->all();

            return [
                'id' => $scope->id,
                'name' => $scope->name,
                'status' => $scope->status,
                'progress_percent' => (int) $scope->progress,
                'job_site' => $scope->jobSite?->only(['id', 'name']),
                'responsible' => $scope->supervisor?->name,
                'can_edit' => $actor->can('update', $scope),
                'pending_change_orders' => (int) ($orders[$scope->id] ?? 0),
                'pending_material_lists' => (int) ($lists[$scope->id] ?? 0),
                'today_shifts' => ($today->get($scope->id) ?? collect())->map(fn (Shift $shift): array => [
                    'id' => $shift->id,
                    'worker' => $shift->worker?->name,
                    'starts_at' => $shift->starts_at->setTimezone($zone)->toIso8601String(),
                    'ends_at' => $shift->ends_at->setTimezone($zone)->toIso8601String(),
                ])->values()->all(),
                'photos' => $photos,
            ];
        })->values()->all();
    }
}
