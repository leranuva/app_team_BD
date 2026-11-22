<?php

namespace Database\Factories;

use App\Enums\ChangeOrderStatus;
use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChangeOrderFactory extends Factory
{
    protected $model = ChangeOrder::class;

    public function definition(): array
    {
        return [
            'job_site_id' => JobSite::factory(),
            'job_scope_id' => function (array $attributes): int {
                return JobScope::query()->create([
                    'job_site_id' => $attributes['job_site_id'],
                    'name' => 'Scope',
                ])->id;
            },
            'created_by' => User::factory(),
            'requested_by' => fn (array $attributes): int => $attributes['created_by'],
            'title' => 'Ampliación de encofrado',
            'description' => 'Trabajo adicional no incluido en el alcance.',
            'status' => ChangeOrderStatus::Draft,
            'amount_cents' => 150000,
            'currency' => 'USD',
            'time_impact_days' => 2,
        ];
    }

    public function submitted(): static
    {
        return $this->state(['status' => ChangeOrderStatus::Submitted, 'submitted_at' => now()]);
    }

    public function underReview(): static
    {
        return $this->state([
            'status' => ChangeOrderStatus::UnderReview,
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => ChangeOrderStatus::Approved,
            'submitted_at' => now()->subHours(2),
            'reviewed_at' => now()->subHour(),
            'approved_at' => now(),
            'applied_to_scope_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => ChangeOrderStatus::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => 'Fuera de presupuesto',
        ]);
    }
}
