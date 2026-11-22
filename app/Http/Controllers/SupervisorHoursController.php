<?php

namespace App\Http\Controllers;

use App\Enums\LogisticsRole;
use App\Models\Attendance;
use App\Models\JobScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SupervisorHoursController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', JobScope::class);

        $actor = $request->user();
        $zone = config('app.business_timezone');
        $start = CarbonImmutable::now($zone)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $end = $start->addWeek();
        $workerIds = User::query()->workersVisibleTo($actor)
            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', [
                LogisticsRole::Carpenter->value,
                LogisticsRole::Helper->value,
            ]))
            ->pluck('id');

        $rows = Attendance::query()->visibleTo($actor)
            ->whereIn('user_id', $workerIds->isEmpty() ? [0] : $workerIds)
            ->where('clocked_in_at', '>=', $start->utc())
            ->where('clocked_in_at', '<', $end->utc())
            ->with('user:id,name')
            ->orderBy('clocked_in_at')
            ->limit(500)
            ->get()
            ->map(fn (Attendance $row): array => [
                'id' => $row->id,
                'worker' => $row->user?->name,
                'clocked_in_at' => $row->clocked_in_at->setTimezone($zone)->toIso8601String(),
                'clocked_out_at' => $row->clocked_out_at?->setTimezone($zone)->toIso8601String(),
                'regular_minutes' => $row->regular_minutes,
                'overtime_minutes' => $row->overtime_minutes,
            ])
            ->values()
            ->all();

        return Inertia::render('Supervisor/HoursApproval', [
            'timeZone' => $zone,
            'week_start' => $start->toDateString(),
            'rows' => $rows,
            // attendances.approved_by_supervisor_at does not exist. approved_at is a different column and is not wired here.
            'approval' => [
                'available' => false,
                'note' => 'La aprobación de horas se gestiona fuera del sistema en este bloque.',
            ],
        ]);
    }
}
