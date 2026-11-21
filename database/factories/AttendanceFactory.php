<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'user_id' => fn (array $data) => Shift::withoutGlobalScopes()->findOrFail($data['shift_id'])->user_id,
            'job_site_id' => fn (array $data) => Shift::withoutGlobalScopes()->findOrFail($data['shift_id'])->job_site_id,
            'clocked_in_at' => fn (array $data) => Shift::withoutGlobalScopes()->findOrFail($data['shift_id'])->starts_at,
            'in_latitude' => 40.75,
            'in_longitude' => -73.9,
        ];
    }
}
