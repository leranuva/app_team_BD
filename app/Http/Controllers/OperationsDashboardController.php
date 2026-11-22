<?php

namespace App\Http\Controllers;

use App\Enums\LogisticsPermission;
use App\Enums\LogisticsRole;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\ScopePhoto;
use App\Models\Shift;
use App\Models\User;
use App\Support\ClockPanel;
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
            'clock' => fn () => app(ClockPanel::class)->for($user),
            'sites' => $canOpenAdmin
                ? JobSite::visibleTo($user)->orderBy('name')->limit(20)->get(['id', 'name', 'address'])
                : [],
            'shifts' => Shift::visibleTo($user)->where('ends_at', '>=', now())
                ->with('jobSite:id,name')->orderBy('starts_at')->limit(20)
                ->get(['id', 'job_site_id', 'starts_at', 'ends_at']),
            'lists' => MaterialList::visibleTo($user)->where('status', 'pending')
                ->with('jobSite:id,name')->latest('id')->limit(20)
                ->get(['id', 'job_site_id', 'name']),
            'photoScope' => $this->photoScope($user),
            'profile' => $this->profile($user),
            'mySites' => $this->mySites($user),
        ]);
    }

    /**
     * Role name already loaded for this request. No extra query and no shared prop.
     */
    private function profile(User $user): string
    {
        if ($user->hasAnyRole([LogisticsRole::SuperAdmin->value, LogisticsRole::Administrator->value])) {
            return 'admin';
        }

        if ($user->hasRole(LogisticsRole::Supervisor->value)) {
            return 'supervisor';
        }

        return 'worker';
    }

    private function mySites(User $user): array
    {
        if ($user->can(LogisticsPermission::SuperviseSites->value)) {
            return JobSite::visibleTo($user)->withOperationalCounts()->orderBy('name')->limit(20)->get()
                ->map(fn (JobSite $site): array => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'active_scopes_count' => (int) $site->active_scopes_count,
                    'active_photos_count' => (int) $site->active_photos_count,
                    'read_only' => false,
                ])->all();
        }

        if (! $user->can(LogisticsPermission::ViewAssignments->value)) {
            return [];
        }

        return JobSite::query()->readableBy($user)->orderBy('name')->limit(20)->get(['id', 'name'])
            ->map(fn (JobSite $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'active_scopes_count' => null,
                'active_photos_count' => null,
                'read_only' => true,
            ])->all();
    }

    private function photoScope(User $user): ?array
    {
        if (! $user->can(LogisticsPermission::SuperviseSites->value)) {
            return null;
        }

        $scope = JobScope::visibleTo($user)
            ->whereIn('status', ['active', 'in_progress', 'pending'])
            ->withCount(['photos' => fn ($photos) => $photos->active()])
            ->orderByRaw("case when status = 'active' then 0 when status = 'in_progress' then 1 else 2 end")
            ->orderBy('name')
            ->first();

        if ($scope === null || ! $user->can('viewAny', [ScopePhoto::class, $scope])) {
            return null;
        }

        return [
            'id' => $scope->id,
            'name' => $scope->name,
            'active_photos_count' => $scope->photos_count,
        ];
    }
}
