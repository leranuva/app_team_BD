<?php

namespace App\Http\Controllers;

use App\Http\Resources\ShiftCalendarOptionsResource;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ShiftCalendarController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Shift::class);
        $options = (new ShiftCalendarOptionsResource($request->user()))->resolve($request);

        return Inertia::render('Shifts/Calendar', [
            'options' => $options,
            'canEdit' => $options['can_edit'],
            'timeZone' => config('app.business_timezone'),
        ]);
    }
}
