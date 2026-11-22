<?php

namespace App\Http\Controllers\Api;

use App\Actions\NotifyOutsideAttendanceGeofence;
use App\Exceptions\OutsideAttendanceGeofence;
use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectAttendanceRequest;
use App\Http\Requests\PunchAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Jobs\RecalculateWeeklyHours;
use App\Models\Attendance;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    use AuthorizesRequests;

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
        $shiftId = $request->validated('shift_id') === null ? null : $request->integer('shift_id');
        $position = [
            (float) $request->validated('lat'), (float) $request->validated('lng'),
            (float) $request->validated('accuracy'), CarbonImmutable::parse($request->validated('timestamp')),
        ];

        try {
            $record = match (true) {
                ! $clockIn => $this->attendance->clockOut($worker, $shiftId, ...$position),
                $shiftId !== null => $this->attendance->clockIn($worker, $shiftId, ...$position),
                default => $this->attendance->clockInAtSite($worker, $request->integer('job_site_id'), ...$position),
            };
        } catch (OutsideAttendanceGeofence $exception) {
            rescue(fn () => $this->notify->execute($worker, $exception->siteId, $shiftId, $clockIn));
            throw $exception;
        }

        return response()->json([
            'data' => new AttendanceResource($record),
            'message' => $clockIn ? 'Clock-in recorded.' : 'Clock-out recorded.',
        ], $clockIn ? 201 : 200);
    }

    public function correct(CorrectAttendanceRequest $request, int $attendance): JsonResponse
    {
        $record = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($attendance);
        $this->authorize('correct', $record);
        $updated = $this->attendance->flagManualCorrection(
            $record,
            $request->user(),
            Carbon::parse($request->validated('clock_in_at')),
            Carbon::parse($request->validated('clock_out_at')),
            $request->validated('correction_reason'),
        );

        RecalculateWeeklyHours::dispatch($updated->user_id, $updated->clocked_in_at->toIso8601String())->afterResponse();

        return (new AttendanceResource($updated))->response();
    }
}
