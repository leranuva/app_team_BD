<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReassignShiftAction;
use App\Enums\ShiftRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\QueryShiftsRequest;
use App\Http\Requests\ReassignShiftRequest;
use App\Http\Resources\ShiftCalendarOptionsResource;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use App\Policies\ShiftPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Shift::class);

        return response()->json((new ShiftCalendarOptionsResource($request->user()))->resolve($request));
    }

    public function index(QueryShiftsRequest $request, ShiftPolicy $policy): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $from = $request->boundary('from');
        $to = $request->boundary('to');
        $query = Shift::forUser($request->user())->overlapping($from, $to);

        $query->when($filters['job_site_id'] ?? null, fn (Builder $shifts, $id) => $shifts->where('job_site_id', $id))
            ->when($filters['worker_id'] ?? null, fn (Builder $shifts, $id) => $shifts->where('user_id', $id)->whereHas('user'))
            ->when($filters['status'] ?? null, fn (Builder $shifts, string $status) => $shifts->where('status', $status))
            ->when($filters['role_required'] ?? null, fn (Builder $shifts, string $value) => $shifts->withRequiredRole(ShiftRole::from($value)));

        $shifts = $from->equalTo($to)
            ? new Collection
            : $query->orderBy('starts_at')->orderBy('id')->limit(2001)->get();

        if ($shifts->count() > 2000) {
            throw ValidationException::withMessages(['to' => 'This request exceeds 2,000 shifts. Choose a shorter date range.']);
        }

        $editable = $shifts->isEmpty() ? [] : $policy->editableShifts($request->user())
            ->whereKey($shifts->modelKeys())->pluck('id')->flip()->all();
        $events = $shifts->load(ShiftResource::relations())->map(
            fn (Shift $shift) => new ShiftResource($shift, isset($editable[$shift->id])),
        );

        return ShiftResource::collection($events)->additional([
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'count' => $shifts->count(),
            ],
        ]);
    }

    public function reassign(ReassignShiftRequest $request, ReassignShiftAction $action): JsonResponse
    {
        $shift = $action(
            $request->shift(),
            $request->instant('start_at'),
            $request->instant('end_at'),
            $request->instant('expected_updated_at'),
            $request->user(),
        );

        return response()->json([
            'data' => new ShiftResource($shift->load(ShiftResource::relations())),
            'message' => 'Shift rescheduled',
        ]);
    }
}
