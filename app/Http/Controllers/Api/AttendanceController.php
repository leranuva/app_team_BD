<?php

namespace App\Http\Controllers\Api;

use App\Actions\NotifyOutsideAttendanceGeofence;
use App\Exceptions\OutsideAttendanceGeofence;
use App\Http\Controllers\Controller;
use App\Http\Requests\PunchAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly NotifyOutsideAttendanceGeofence $notify,
    ) {}

    public function clockIn(PunchAttendanceRequest $request): JsonResponse
    {
        return $this->punch($request, true);
    }

    public function clockOut(PunchAttendanceRequest $request): JsonResponse
    {
        return $this->punch($request, false);
    }

    private function punch(PunchAttendanceRequest $request, bool $clockIn): JsonResponse
    {
        $worker = $request->user();
        $shiftId = $request->integer('shift_id');
        $position = [
            $worker, $shiftId, (float) $request->validated('lat'), (float) $request->validated('lng'),
            (float) $request->validated('accuracy'), CarbonImmutable::parse($request->validated('timestamp')),
        ];

        try {
            $record = $clockIn ? $this->attendance->clockIn(...$position) : $this->attendance->clockOut(...$position);
        } catch (OutsideAttendanceGeofence $exception) {
            rescue(fn () => $this->notify->execute($worker, $exception->siteId, $shiftId, $clockIn));
            throw $exception;
        }

        return response()->json([
            'data' => new AttendanceResource($record),
            'message' => $clockIn ? 'Entrada registrada.' : 'Salida registrada.',
        ], $clockIn ? 201 : 200);
    }
}
