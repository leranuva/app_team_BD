<?php

return [
    'clock_in_window_minutes' => 30,
    'clock_out_window_minutes' => null, // Record the actual departure, including early transfers.
    'max_accuracy_meters' => 100,
    'max_sample_age_seconds' => 120,
    'workweek_starts_on' => 1, // ISO weekday: Monday.
    'weekly_regular_minutes' => 2400,
    'daily_regular_minutes' => 480, // Internal policy; null disables the daily threshold.
    'daily_double_time_after_minutes' => null,
    'overtime_multiplier' => '1.5',
    'double_time_multiplier' => '2.0',
];
