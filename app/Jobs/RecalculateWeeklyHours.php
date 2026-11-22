<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Services\AttendanceCalculatorService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateWeeklyHours implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId, public string $instant)
    {
        $this->onQueue('attendance');
    }

    public function handle(AttendanceCalculatorService $calculator): void
    {
        $start = $calculator->weekStart(CarbonImmutable::parse($this->instant));
        $end = $start->addWeek();
        $rows = Attendance::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->where('user_id', $this->userId)
            ->where('clocked_in_at', '>=', $start->utc())
            ->where('clocked_in_at', '<', $end->utc())
            ->whereNotNull('clocked_out_at')
            ->orderBy('clocked_in_at')
            ->orderBy('id')
            ->get();

        $history = [];
        foreach ($rows as $row) {
            $minutes = $calculator->calculate($row->clocked_in_at, $row->clocked_out_at, $history);
            $changed = $row->duration_minutes !== $minutes['duration_minutes']
                || $row->regular_minutes !== $minutes['regular_minutes']
                || $row->overtime_minutes !== $minutes['overtime_minutes']
                || $row->double_time_minutes !== $minutes['double_time_minutes'];
            if ($changed) {
                $row->forceFill($minutes)->save();
            }
            $history[] = $row;
        }
    }
}
