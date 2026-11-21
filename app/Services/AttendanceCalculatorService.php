<?php

namespace App\Services;

use App\Models\Attendance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AttendanceCalculatorService
{
    public function weekStart(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(config('app.business_timezone'))
            ->startOfWeek((int) config('attendance.workweek_starts_on'));
    }

    public function estimateGrossPay(array $minutes, string $hourlyRate): string
    {
        $weightedMinutes = BigDecimal::of($minutes['regular_minutes'])
            ->plus(BigDecimal::of($minutes['overtime_minutes'])->multipliedBy(config('attendance.overtime_multiplier')))
            ->plus(BigDecimal::of($minutes['double_time_minutes'])->multipliedBy(config('attendance.double_time_multiplier')));

        return (string) $weightedMinutes->multipliedBy($hourlyRate)->dividedBy(60, 2, RoundingMode::HalfUp);
    }

    /**
     * History consists of earlier closed entries for the same worker, ordered by service time.
     * Entries and the target must not overlap. Thresholds apply to all worksites together.
     *
     * @param  iterable<Attendance>  $history
     * @return array{duration_minutes: int, regular_minutes: int, overtime_minutes: int, double_time_minutes: int}
     */
    public function calculate(CarbonInterface $start, CarbonInterface $end, iterable $history = []): array
    {
        $weeklyLimit = (int) config('attendance.weekly_regular_minutes') * 60;
        $dailyLimit = $this->secondsOrUnlimited(config('attendance.daily_regular_minutes'));
        $doubleLimit = $this->secondsOrUnlimited(config('attendance.daily_double_time_after_minutes'));
        $weeks = [];
        $days = [];
        $window = $this->weekStart($start);

        foreach ($history as $attendance) {
            if ($attendance->clocked_out_at === null || $attendance->clocked_out_at->lessThanOrEqualTo($window)) {
                continue;
            }

            $historyStart = $attendance->clocked_in_at->max($window);
            foreach ($this->segments($historyStart, $attendance->clocked_out_at->min($start)) as [$day, $week, $seconds]) {
                $weeks[$week] = ($weeks[$week] ?? 0) + $seconds;
                $days[$day] = ($days[$day] ?? 0) + $seconds;
            }
        }

        $totals = ['regular_minutes' => 0, 'overtime_minutes' => 0, 'double_time_minutes' => 0];
        foreach ($this->segments($start, $end) as [$day, $week, $seconds]) {
            $dayUsed = $days[$day] ?? 0;
            $weekUsed = $weeks[$week] ?? 0;
            $beforeDouble = min($seconds, max(0, $doubleLimit - $dayUsed));
            $regular = min($beforeDouble, max(0, $dailyLimit - $dayUsed), max(0, $weeklyLimit - $weekUsed));

            $totals['regular_minutes'] += $regular;
            $totals['overtime_minutes'] += $beforeDouble - $regular;
            $totals['double_time_minutes'] += $seconds - $beforeDouble;
            $days[$day] = $dayUsed + $seconds;
            $weeks[$week] = $weekUsed + $seconds;
        }

        $minutes = [];
        $cumulative = 0;
        foreach ($totals as $category => $seconds) {
            $minutes[$category] = intdiv($cumulative + $seconds, 60) - intdiv($cumulative, 60);
            $cumulative += $seconds;
        }

        return ['duration_minutes' => intdiv($cumulative, 60)] + $minutes;
    }

    private function segments(CarbonInterface $start, CarbonInterface $end): iterable
    {
        $cursor = CarbonImmutable::instance($start)->setTimezone(config('app.business_timezone'));

        while ($cursor->lessThan($end)) {
            $next = $cursor->startOfDay()->addDay()->min($end);

            yield [$cursor->toDateString(), $this->weekStart($cursor)->toDateString(), $next->timestamp - $cursor->timestamp];

            $cursor = $next;
        }
    }

    private function secondsOrUnlimited(?int $minutes): int
    {
        return $minutes === null ? PHP_INT_MAX : $minutes * 60;
    }
}
