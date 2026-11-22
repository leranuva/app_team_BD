<?php

namespace Database\Factories;

use App\Enums\ShiftRole;
use App\Enums\ShiftStatus;
use App\Models\JobSite;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

class ShiftFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDay()->startOfHour();

        return [
            'job_site_id' => JobSite::factory(),
            'user_id' => User::factory()->afterCreating(fn (User $worker) => $worker->assignRole(Role::findOrCreate(ShiftRole::Carpenter->logisticsRole()->value, 'web'))),
            'role_required' => ShiftRole::Carpenter,
            'status' => ShiftStatus::Programmed,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(8),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Shift $shift) => $shift->worker->jobSites()->syncWithoutDetaching([$shift->job_site_id]));
    }

    public function cancelled(): static
    {
        return $this->state(['status' => ShiftStatus::Cancelled]);
    }

    public function confirmed(): static
    {
        return $this->state(['status' => ShiftStatus::Confirmed]);
    }
}
