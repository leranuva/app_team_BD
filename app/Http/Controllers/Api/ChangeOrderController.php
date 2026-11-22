<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveChangeOrderRequest;
use App\Http\Requests\CancelChangeOrderRequest;
use App\Http\Requests\RejectChangeOrderRequest;
use App\Http\Resources\ChangeOrderResource;
use App\Models\ChangeOrder;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Services\ChangeOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ChangeOrderController extends Controller
{
    public function __construct(private readonly ChangeOrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ChangeOrder::class);
        $filters = $request->validate([
            'job_site_id' => ['nullable', 'integer'],
            'job_scope_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        $orders = ChangeOrder::query()->forUser($request->user())
            ->with(['jobSite:id,name', 'jobScope:id,name', 'requestedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'attachments'])
            ->when($filters['job_site_id'] ?? null, fn ($query, int $id) => $query->forSite($id))
            ->when($filters['job_scope_id'] ?? null, fn ($query, int $id) => $query->forScope($id))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->limit(100)
            ->get();

        return ChangeOrderResource::collection($orders);
    }

    public function show(Request $request, int $order): ChangeOrderResource
    {
        return new ChangeOrderResource($this->visible($request, $order));
    }

    public function submit(Request $request, int $order): ChangeOrderResource
    {
        $record = $this->visible($request, $order);

        return new ChangeOrderResource($this->orders->submit($record, $request->user())->load($this->relations()));
    }

    public function startReview(Request $request, int $order): ChangeOrderResource
    {
        $record = $this->visible($request, $order);

        return new ChangeOrderResource($this->orders->startReview($record, $request->user())->load($this->relations()));
    }

    public function approve(ApproveChangeOrderRequest $request, int $order): ChangeOrderResource
    {
        $record = $this->visible($request, $order);

        return new ChangeOrderResource($this->orders->approve($record, $request->user(), $request->validated('note'))->load($this->relations()));
    }

    public function reject(RejectChangeOrderRequest $request, int $order): ChangeOrderResource
    {
        $record = $this->visible($request, $order);

        return new ChangeOrderResource($this->orders->reject($record, $request->user(), $request->validated('reason'))->load($this->relations()));
    }

    public function cancel(CancelChangeOrderRequest $request, int $order): ChangeOrderResource
    {
        $record = $this->visible($request, $order);

        return new ChangeOrderResource($this->orders->cancel($record, $request->user(), $request->validated('reason'))->load($this->relations()));
    }

    private function visible(Request $request, int $order): ChangeOrder
    {
        $record = ChangeOrder::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->with($this->relations())
            ->findOrFail($order);
        Gate::authorize('view', $record);

        return $record;
    }

    private function relations(): array
    {
        return ['jobSite:id,name', 'jobScope:id,name', 'requestedBy:id,name', 'reviewedBy:id,name', 'approvedBy:id,name', 'attachments'];
    }
}
