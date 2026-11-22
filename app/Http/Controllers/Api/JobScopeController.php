<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateJobScopeProgressRequest;
use App\Http\Resources\JobScopeResource;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class JobScopeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'job_site_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,active,in_progress,completed'],
        ]);

        if (isset($filters['job_site_id'])) {
            $site = JobSite::withoutGlobalScope(LogisticsVisibilityScope::class)->find($filters['job_site_id']);
            abort_if($site === null, 404);
            Gate::authorize('read', $site);
        } else {
            Gate::authorize('readAny', JobScope::class);
        }

        $scopes = JobScope::query()->readableBy($request->user())
            ->with(['jobSite:id,name', 'supervisor:id,name'])
            ->withOperationalCounts()
            ->when($filters['job_site_id'] ?? null, fn ($query, int $siteId) => $query->where('job_site_id', $siteId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(200)
            ->get();

        return JobScopeResource::collection($scopes)->response();
    }

    public function show(int $scope): JsonResponse
    {
        $record = JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->with(['jobSite:id,name', 'supervisor:id,name'])
            ->withOperationalCounts()
            ->findOrFail($scope);
        Gate::authorize('read', $record);

        return (new JobScopeResource($record))->response();
    }

    public function update(UpdateJobScopeProgressRequest $request, int $scope): JsonResponse
    {
        $record = JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($scope);
        Gate::authorize('update', $record);

        $progress = $request->integer('progress');
        if ($progress === 100 && $record->status !== 'completed') {
            throw ValidationException::withMessages([
                'progress' => 'Progress can reach 100% only after the scope is complete.',
            ]);
        }

        $record->progress = $progress;
        $record->save();
        $record->load(['jobSite:id,name', 'supervisor:id,name']);

        return (new JobScopeResource($record))->response();
    }
}
