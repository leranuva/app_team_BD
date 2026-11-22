<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkerHistoryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Attendance::class);

        $user = $request->user();
        $zone = config('app.business_timezone');
        $from = CarbonImmutable::now($zone)->subWeeks(4)->startOfDay();

        $page = Attendance::visibleTo($user)
            ->where('user_id', $user->id)
            ->where('clocked_in_at', '>=', $from->utc())
            ->with(['jobSite' => fn ($site) => $site->select('id', 'name')])
            ->orderByDesc('clocked_in_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Worker/History', [
            'timeZone' => $zone,
            'attendances' => [
                'data' => $page->getCollection()->map(fn (Attendance $row): array => [
                    'id' => $row->id,
                    'job_site' => $row->jobSite?->name,
                    'clocked_in_at' => $row->clocked_in_at->setTimezone($zone)->toIso8601String(),
                    'clocked_out_at' => $row->clocked_out_at?->setTimezone($zone)->toIso8601String(),
                    'duration_minutes' => $row->duration_minutes,
                    'overtime_minutes' => $row->overtime_minutes,
                ])->values()->all(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
