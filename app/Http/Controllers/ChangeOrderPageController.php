<?php

namespace App\Http\Controllers;

use App\Filament\Resources\ChangeOrders\ChangeOrderResource as AdminChangeOrderResource;
use App\Http\Resources\ChangeOrderResource;
use App\Models\ChangeOrder;
use App\Models\Scopes\LogisticsVisibilityScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChangeOrderPageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ChangeOrder::class);
        $filters = $request->validate([
            'job_site_id' => ['nullable', 'integer'],
            'job_scope_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);
        $user = $request->user();

        $orders = ChangeOrder::query()->forUser($user)
            ->with(['jobSite:id,name', 'jobScope:id,name', 'requestedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'attachments'])
            ->when($filters['job_site_id'] ?? null, fn ($query, int $id) => $query->forSite($id))
            ->when($filters['job_scope_id'] ?? null, fn ($query, int $id) => $query->forScope($id))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->limit(100)
            ->get();

        return Inertia::render('ChangeOrders/Index', [
            'orders' => ChangeOrderResource::collection($orders)->resolve(),
            'filters' => $filters,
            'canCreate' => $user->can('create', ChangeOrder::class),
            'createUrl' => AdminChangeOrderResource::getUrl('create', panel: 'admin'),
        ]);
    }

    public function show(Request $request, int $order): Response
    {
        $record = ChangeOrder::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->with(['jobSite:id,name', 'jobScope:id,name', 'requestedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'attachments'])
            ->findOrFail($order);
        Gate::authorize('view', $record);

        return Inertia::render('ChangeOrders/Show', [
            'order' => (new ChangeOrderResource($record))->resolve(),
        ]);
    }
}
