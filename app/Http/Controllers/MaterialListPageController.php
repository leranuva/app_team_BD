<?php

namespace App\Http\Controllers;

use App\Filament\Resources\MaterialLists\MaterialListResource as AdminMaterialListResource;
use App\Http\Resources\MaterialListResource;
use App\Models\MaterialList;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MaterialListPageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MaterialList::class);
        $filters = $request->validate([
            'job_site_id' => ['nullable', 'integer'],
            'job_scope_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);
        $user = $request->user();
        $records = MaterialList::query()->forUser($user)
            ->with(['jobSite:id,name', 'jobScope:id,name', 'requestedBy:id,name', 'approvedBy:id,name', 'items.material:id,code,name,unit'])
            ->when($filters['job_site_id'] ?? null, fn ($query, int $id) => $query->forSite($id))
            ->when($filters['job_scope_id'] ?? null, fn ($query, int $id) => $query->forScope($id))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('title')
            ->limit(100)
            ->get();

        return Inertia::render('MaterialLists/Index', [
            'lists' => MaterialListResource::collection($records)->resolve(),
            'filters' => $filters,
            'canCreate' => $user->can('create', MaterialList::class),
            'createUrl' => AdminMaterialListResource::getUrl('create', panel: 'admin'),
        ]);
    }

    public function show(Request $request, int $list): Response
    {
        $record = MaterialList::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->with(['jobSite:id,name', 'jobScope:id,name', 'requestedBy:id,name', 'approvedBy:id,name', 'items.material:id,code,name,unit'])
            ->findOrFail($list);
        Gate::authorize('view', $record);

        return Inertia::render('MaterialLists/Show', [
            'list' => (new MaterialListResource($record))->resolve(),
        ]);
    }
}
