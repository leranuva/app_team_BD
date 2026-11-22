<?php

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Exceptions\OutsideAttendanceGeofence;
use App\Models\Attendance;
use App\Models\JobSite;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(private readonly AttendanceCalculatorService $calculator) {}

    public function clockIn(User $actor, int $shiftId, float $lat, float $lng, float $accuracy, ?CarbonInterface $sampledAt = null): Attendance
    {
        return DB::transaction(function () use ($actor, $shiftId, $lat, $lng, $accuracy, $sampledAt): Attendance {
            $worker = User::query()->lockForUpdate()->findOrFail($actor->id);
            $shift = Shift::withoutGlobalScope(LogisticsVisibilityScope::class)->lockForUpdate()->findOrFail($shiftId);
            Gate::forUser($worker)->authorize('clockIn', [Attendance::class, $shift]);

            if (! in_array($shift->status, [ShiftStatus::Programmed, ShiftStatus::Confirmed], true)) {
                throw ValidationException::withMessages(['shift_id' => 'El turno no admite una nueva entrada.']);
            }

            $entries = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)->where('user_id', $worker->id);
            if ((clone $entries)->whereNull('clocked_out_at')->exists()) {
                throw ValidationException::withMessages(['shift_id' => 'Ya tienes una entrada abierta.']);
            }
            if ((clone $entries)->where('shift_id', $shift->id)->exists()) {
                throw ValidationException::withMessages(['shift_id' => 'Este turno ya tiene una asistencia registrada.']);
            }

            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $this->ensureWindow($shift->starts_at, $now, 'clock_in_window_minutes');
            $site = JobSite::visibleTo($worker)->findOrFail($shift->job_site_id);
            $flagged = $this->checkPosition($site, $lat, $lng, $accuracy, $now, $sampledAt);

            $attendance = new Attendance([
                'user_id' => $worker->id, 'job_site_id' => $site->id, 'shift_id' => $shift->id,
                'clocked_in_at' => $now, 'in_latitude' => $lat, 'in_longitude' => $lng,
            ]);
            $attendance->recordedBy($worker)->forceFill(['in_accuracy_meters' => $accuracy, 'geofence_flagged' => $flagged])->save();

            return $attendance;
        }, attempts: 3);
    }

    public function clockOut(User $actor, int $shiftId, float $lat, float $lng, float $accuracy, ?CarbonInterface $sampledAt = null): Attendance
    {
        return DB::transaction(function () use ($actor, $shiftId, $lat, $lng, $accuracy, $sampledAt): Attendance {
            $worker = User::query()->lockForUpdate()->findOrFail($actor->id);
            $shift = Shift::withoutGlobalScope(LogisticsVisibilityScope::class)->lockForUpdate()->findOrFail($shiftId);
            Gate::forUser($worker)->authorize('clockOutShift', [Attendance::class, $shift]);
            $entries = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class);
            $attendance = (clone $entries)->where('shift_id', $shift->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($worker)->authorize('clockOut', $attendance);

            if ($attendance->clocked_out_at !== null) {
                throw ValidationException::withMessages(['shift_id' => 'La asistencia ya tiene una salida.']);
            }

            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if ($now->lessThanOrEqualTo($attendance->clocked_in_at)) {
                throw ValidationException::withMessages(['timestamp' => 'La salida debe ser posterior a la entrada.']);
            }

            $this->ensureWindow($shift->ends_at, $now, 'clock_out_window_minutes');
            $site = JobSite::withoutGlobalScope(LogisticsVisibilityScope::class)->withTrashed()->findOrFail($shift->job_site_id);
            $flagged = $this->checkPosition($site, $lat, $lng, $accuracy, $now, $sampledAt);
            $history = $entries->where('user_id', $worker->id)
                ->where('clocked_out_at', '>', $this->calculator->weekStart($attendance->clocked_in_at)->utc())
                ->where('clocked_out_at', '<=', $attendance->clocked_in_at)
                ->orderBy('clocked_in_at')->get(['clocked_in_at', 'clocked_out_at']);

            $attendance->recordedBy($worker)->forceFill([
                'clocked_out_at' => $now, 'out_latitude' => $lat, 'out_longitude' => $lng,
                'out_accuracy_meters' => $accuracy,
                'geofence_flagged' => $attendance->geofence_flagged || $flagged,
            ] + $this->calculator->calculate($attendance->clocked_in_at, $now, $history))->save();

            return $attendance;
        }, attempts: 3);
    }

    public function flagManualCorrection(Attendance $attendance, User $actor, CarbonInterface $newIn, CarbonInterface $newOut, string $reason): Attendance
    {
        return DB::transaction(function () use ($attendance, $actor, $newIn, $newOut, $reason): Attendance {
            $locked = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)->lockForUpdate()->findOrFail($attendance->id);
            Gate::forUser($actor)->authorize('correct', $locked);

            if ($newOut->lessThanOrEqualTo($newIn)) {
                throw ValidationException::withMessages(['clock_out_at' => 'La salida debe ser posterior a la entrada.']);
            }

            $others = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)
                ->where('user_id', $locked->user_id)
                ->whereKeyNot($locked->id);

            if ((clone $others)
                ->where('clocked_in_at', '<', $newOut)
                ->where(fn ($query) => $query->whereNull('clocked_out_at')->orWhere('clocked_out_at', '>', $newIn))
                ->exists()) {
                throw ValidationException::withMessages(['clock_in_at' => 'La corrección se solapa con otra asistencia.']);
            }

            $history = (clone $others)
                ->where('clocked_out_at', '>', $this->calculator->weekStart($newIn)->utc())
                ->where('clocked_out_at', '<=', $newIn)
                ->orderBy('clocked_in_at')
                ->get(['clocked_in_at', 'clocked_out_at']);

            $locked->correctedBy($actor, $reason)->forceFill([
                'clocked_in_at' => $newIn,
                'clocked_out_at' => $newOut,
                'corrected_by' => $actor->id,
                'correction_reason' => $reason,
            ] + $this->calculator->calculate($newIn, $newOut, $history))->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    private function ensureWindow(CarbonInterface $scheduled, CarbonImmutable $now, string $key): void
    {
        $minutes = config('attendance.'.$key);
        if ($minutes !== null && abs($scheduled->timestamp - $now->timestamp) > $minutes * 60) {
            throw ValidationException::withMessages(['timestamp' => 'El fichaje está fuera de la ventana del turno.']);
        }
    }

    private function checkPosition(JobSite $site, float $lat, float $lng, float $accuracy, CarbonImmutable $now, ?CarbonInterface $sampledAt): bool
    {
        if (! is_finite($lat) || abs($lat) > 90 || ! is_finite($lng) || abs($lng) > 180
            || ! is_finite($accuracy) || $accuracy < 0 || $accuracy > 9_999_999_999.99) {
            throw ValidationException::withMessages(['location' => 'Las coordenadas o la precisión no son válidas.']);
        }
        if ($sampledAt !== null && abs($sampledAt->timestamp - $now->timestamp) > config('attendance.max_sample_age_seconds')) {
            throw ValidationException::withMessages(['timestamp' => 'Actualiza la ubicación antes de fichar.']);
        }

        $siteLat = deg2rad((float) $site->latitude);
        $latitude = deg2rad($lat);
        $longitudeDelta = deg2rad($lng - (float) $site->longitude);
        $haversine = sin(($latitude - $siteLat) / 2) ** 2
            + cos($siteLat) * cos($latitude) * sin($longitudeDelta / 2) ** 2;
        $distance = 6_371_000 * 2 * asin(sqrt(min(1, max(0, $haversine))));

        if ($distance > $site->geofence_meters) {
            throw new OutsideAttendanceGeofence($site->id);
        }

        return $accuracy > config('attendance.max_accuracy_meters');
    }
}
