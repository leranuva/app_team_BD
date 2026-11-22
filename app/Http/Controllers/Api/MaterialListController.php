<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveMaterialListRequest;
use App\Http\Requests\CancelMaterialListRequest;
use App\Http\Requests\RegisterMaterialListConsumptionRequest;
use App\Http\Requests\RegisterMaterialListDeliveryRequest;
use App\Http\Requests\StoreMaterialListRequest;
use App\Http\Requests\UpdateMaterialListRequest;
use App\Http\Resources\MaterialListResource;
use App\Models\JobScope;
use App\Models\MaterialList;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Services\MaterialListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaterialListController extends Controller
{
    public function __construct(private readonly MaterialListService $lists) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MaterialList::class);
        $filters = $request->validate([
            'job_site_id' => ['nullable', 'integer'],
            'job_scope_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        $records = MaterialList::query()->forUser($request->user())
            ->with($this->relations())
            ->when($filters['job_site_id'] ?? null, fn ($query, int $id) => $query->forSite($id))
            ->when($filters['job_scope_id'] ?? null, fn ($query, int $id) => $query->forScope($id))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('title')
            ->limit(100)
            ->get();

        return MaterialListResource::collection($records)->response();
    }

    public function show(Request $request, int $list): JsonResponse
    {
        return (new MaterialListResource($this->visible($request, $list)))->response();
    }

    public function store(StoreMaterialListRequest $request): JsonResponse
    {
        $scope = JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($request->integer('job_scope_id'));
        $list = $this->lists->create($request->user(), $scope, $request->validated());

        return (new MaterialListResource($list->load($this->relations())))->response()->setStatusCode(201);
    }

    public function update(UpdateMaterialListRequest $request, int $list): JsonResponse
    {
        $record = $this->visible($request, $list);
        $updated = $this->lists->update($record, $request->user(), $request->validated());

        return (new MaterialListResource($updated->load($this->relations())))->response();
    }

    public function submit(Request $request, int $list): JsonResponse
    {
        $record = $this->visible($request, $list);

        return (new MaterialListResource($this->lists->submit($record, $request->user())->load($this->relations())))->response();
    }

    public function approve(ApproveMaterialListRequest $request, int $list): JsonResponse
    {
        $record = $this->visible($request, $list);

        return (new MaterialListResource($this->lists->approve($record, $request->user(), $request->validated('approved') ?? [])->load($this->relations())))->response();
    }

    public function markOrdered(Request $request, int $list): JsonResponse
    {
        $record = $this->visible($request, $list);

        return (new MaterialListResource($this->lists->markOrdered($record, $request->user())->load($this->relations())))->response();
    }

    public function registerDelivery(RegisterMaterialListDeliveryRequest $request, int $list): JsonResponse
    {
        $record = $this->visible($request, $list);

        return (new MaterialListResource($this->lists->registerDelivery(
            $record,
            $request->user(),
            $request->validated('delivered'),
            (bool) $request->boolean('confirm'),
        )->load($this->relations())))->response();
    }

    public function registerConsumption(RegisterMaterialListConsumptionRequest $request, int $list): JsonResponse
    {
        $record = $this->visible($request, $list);

        return (new MaterialListResource($this->lists->registerConsumption(
            $record,
            $request->user(),
            $request->validated('consumed'),
            (bool) $request->boolean('confirm'),
        )->load($this->relations())))->response();
    }

    public function cancel(CancelMaterialListRequest $request, int $list): JsonResponse
    {
        $record = $this->visible($request, $list);

        return (new MaterialListResource($this->lists->cancel($record, $request->user(), $request->validated('reason'))->load($this->relations())))->response();
    }

    private function visible(Request $request, int $list): MaterialList
    {
        $record = MaterialList::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->with($this->relations())
            ->findOrFail($list);
        Gate::authorize('view', $record);

        return $record;
    }

    private function relations(): array
    {
        return ['jobSite:id,name', 'jobScope:id,name', 'requestedBy:id,name', 'approvedBy:id,name', 'items.material:id,code,name,unit'];
    }
}
