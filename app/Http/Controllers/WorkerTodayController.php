<?php

namespace App\Http\Controllers;

use App\Enums\ShiftStatus;
use App\Models\MaterialListItem;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Support\ClockPanel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkerTodayController extends Controller
{
    public function __invoke(Request $request, ClockPanel $clock): Response
    {
        Gate::authorize('viewAny', Shift::class);

        $user = $request->user();
        $zone = config('app.business_timezone');
        $start = CarbonImmutable::now($zone)->startOfDay();
        $end = $start->addDay();

        $shifts = Shift::forUser($user)->where('user_id', $user->id)
            ->where('starts_at', '<', $end->utc())
            ->where('ends_at', '>', $start->utc())
            ->where('status', '!=', ShiftStatus::Cancelled->value)
            ->with([
                'jobSite:id,name,address',
                'requiredRole:id,name',
                'jobScope' => fn ($scope) => $scope->withoutGlobalScope(LogisticsVisibilityScope::class)->select('id', 'name', 'supervisor_id'),
                'jobScope.supervisor:id,name',
                'attendance:id,shift_id,clocked_in_at,clocked_out_at',
            ])
            ->orderBy('starts_at')
            ->get();

        $materialsByScope = $shifts->whereNotNull('job_scope_id')->unique('job_scope_id')
            ->mapWithKeys(fn (Shift $shift): array => [$shift->job_scope_id => $this->materials($user->id, $shift)]);

        return Inertia::render('Worker/Today', [
            'timeZone' => $zone,
            'clock' => $clock->for($user),
            'shifts' => $shifts->map(fn (Shift $shift): array => $this->shiftPayload($shift) + [
                'materials' => $materialsByScope->get($shift->job_scope_id, []),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftPayload(Shift $shift): array
    {
        $zone = config('app.business_timezone');
        $address = $shift->jobSite?->address;

        return [
            'id' => $shift->id,
            'starts_at' => $shift->starts_at->setTimezone($zone)->toIso8601String(),
            'ends_at' => $shift->ends_at->setTimezone($zone)->toIso8601String(),
            'role_required' => $shift->effectiveRole()?->label(),
            'notes' => $shift->notes,
            'is_unscheduled' => $shift->is_unscheduled,
            'job_site' => [
                'name' => $shift->jobSite?->name,
                'address' => $address,
                'maps_url' => $address
                    ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address)
                    : null,
            ],
            'job_scope' => $shift->jobScope?->only(['id', 'name']),
            'supervisor' => $shift->jobScope?->supervisor?->name,
            'clocked_in_at' => $shift->attendance?->clocked_in_at?->setTimezone($zone)->toIso8601String(),
            'clocked_out_at' => $shift->attendance?->clocked_out_at?->setTimezone($zone)->toIso8601String(),
        ];
    }

    /**
     * Lists are not linked to a shift. Items appear only when the worker is assigned
     * a list whose scope is the scope of today's shift.
     *
     * @return list<array{id: int, material: string|null, quantity: string, unit: string|null, reference_photo_url: string|null}>
     */
    private function materials(int $userId, Shift $shift): array
    {
        if ($shift->job_scope_id === null) {
            return [];
        }

        return MaterialListItem::query()
            ->whereHas('materialList', fn ($lists) => $lists
                ->where('assigned_to', $userId)
                ->where('job_scope_id', $shift->job_scope_id))
            ->with('material:id,code,name,unit')
            ->orderBy('id')
            ->limit(30)
            ->get()
            ->map(fn (MaterialListItem $item): array => [
                'id' => $item->id,
                'material' => $item->material?->name,
                'quantity' => (string) ($item->requested_quantity ?? $item->budgeted_quantity),
                'unit' => $item->material?->unit,
                'reference_photo_url' => $item->material?->referencePhotoUrl(),
            ])
            ->values()
            ->all();
    }
}
