<?php

namespace App\Http\Controllers;

use App\Enums\LogisticsPermission;
use App\Filament\Resources\JobScopes\JobScopeResource as ScopeAdminResource;
use App\Filament\Resources\JobSites\JobSiteResource as SiteAdminResource;
use App\Models\JobScope;
use App\Models\ScopePhoto;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JobScopeController extends Controller
{
    public function show(Request $request, int $scope): Response
    {
        $record = JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->with(['jobSite:id,name', 'supervisor:id,name'])
            ->findOrFail($scope);
        Gate::authorize('viewAny', [ScopePhoto::class, $record]);
        $user = $request->user();
        $canEdit = $user->can('update', $record);

        return Inertia::render('JobScopes/Show', [
            'scope' => [
                'id' => $record->id,
                'name' => $record->name,
                'status' => $record->status,
                'description' => $record->description,
                'notes' => $record->notes,
                'progress_percent' => (int) $record->progress,
                'start_date' => $record->start_date?->toDateString(),
                'estimated_end_date' => $record->estimated_end_date?->toDateString(),
                'responsible' => $record->supervisor?->only(['id', 'name']),
                'job_site' => $record->jobSite?->only(['id', 'name']),
                'site_url' => $record->jobSite && $user->can('view', $record->jobSite)
                    ? SiteAdminResource::getUrl('view', ['record' => $record->jobSite], panel: 'admin')
                    : null,
                'can_edit' => $canEdit,
                'edit_url' => $canEdit
                    ? ScopeAdminResource::getUrl('edit', ['record' => $record], panel: 'admin')
                    : null,
            ],
            'shifts' => Shift::withoutGlobalScope(LogisticsVisibilityScope::class)
                ->where('job_scope_id', $record->id)
                ->with('worker:id,name')
                ->orderBy('starts_at')
                ->limit(50)
                ->get(['id', 'user_id', 'starts_at', 'status'])
                ->map(fn (Shift $shift): array => [
                    'id' => $shift->id,
                    'worker' => $shift->worker?->name,
                    'starts_at' => $shift->starts_at->timezone(config('app.business_timezone'))->toIso8601String(),
                    'status' => $shift->status->label(),
                ])
                ->all(),
            'canCreate' => $user->can('create', [ScopePhoto::class, $record]),
            'canViewAll' => $user->can(LogisticsPermission::GlobalAccess->value),
            'timeZone' => config('app.business_timezone'),
        ]);
    }
}
