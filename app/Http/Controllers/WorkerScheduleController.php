<?php

namespace App\Http\Controllers;

use App\Http\Resources\ShiftCalendarOptionsResource;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkerScheduleController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Shift::class);
        $options = (new ShiftCalendarOptionsResource($request->user()))->resolve($request);

        return Inertia::render('Worker/Schedule', [
            'options' => $options,
            'timeZone' => config('app.business_timezone'),
        ]);
    }
}
