<?php

namespace App\Actions;

use App\Enums\LogisticsRole;
use App\Models\JobSite;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\User;
use App\Notifications\AttendanceOutsideGeofenceNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotifyOutsideAttendanceGeofence
{
    public function execute(User $worker, int $siteId, int $shiftId, bool $clockIn): void
    {
        $operation = $clockIn ? 'entrada' : 'salida';
        $key = "attendance-geofence:{$worker->id}:{$shiftId}:".($clockIn ? 'in' : 'out');
        $site = JobSite::withoutGlobalScope(LogisticsVisibilityScope::class)->withTrashed()->findOrFail($siteId);
        $supervisors = $site->users()->active()->role(LogisticsRole::Supervisor->value)->get();
        if ($supervisors->isEmpty() || ! Cache::add($key, true, now()->addMinutes(5))) {
            return;
        }

        try {
            Notification::send($supervisors, new AttendanceOutsideGeofenceNotification([
                'job_site_id' => $siteId,
                'job_site_name' => $site->name,
                'shift_id' => $shiftId,
                'worker_id' => $worker->id,
                'worker_name' => $worker->name,
                'operation' => $operation,
                'attempted_at' => now(config('app.business_timezone'))->toIso8601String(),
            ]));
        } catch (Throwable $exception) {
            Cache::forget($key);
            throw $exception;
        }
    }
}
