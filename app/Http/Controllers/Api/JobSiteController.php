<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobSiteResource;
use App\Models\JobSite;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JobSiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('readAny', JobSite::class);

        $sites = JobSite::query()->readableBy($request->user())
            ->with('supervisors')
            ->withOperationalCounts()
            ->orderBy('name')
            ->orderBy('id')
            ->limit(200)
            ->get();

        return JobSiteResource::collection($sites)->response();
    }

    public function show(Request $request, int $site): JsonResponse
    {
        $record = JobSite::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->with('supervisors')
            ->withOperationalCounts()
            ->findOrFail($site);
        Gate::authorize('read', $record);

        return (new JobSiteResource($record))->response();
    }
}
