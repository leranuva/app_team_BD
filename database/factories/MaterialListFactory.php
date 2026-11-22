<?php

namespace Database\Factories;

use App\Enums\MaterialListStatus;
use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaterialListFactory extends Factory
{
    protected $model = MaterialList::class;

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
            'requested_by' => User::factory(),
            'assigned_to' => fn (array $attributes): int => $attributes['requested_by'],
            'name' => 'Lista de obra',
            'title' => 'Lista de obra',
            'status' => MaterialListStatus::Draft,
            'notes' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(['status' => MaterialListStatus::Submitted]);
    }

    public function approved(): static
    {
        return $this->state(['status' => MaterialListStatus::Approved]);
    }

    public function ordered(): static
    {
        return $this->state(['status' => MaterialListStatus::Ordered, 'ordered_at' => now()]);
    }

    public function delivered(): static
    {
        return $this->state(['status' => MaterialListStatus::Delivered, 'ordered_at' => now()->subHour(), 'delivered_at' => now()]);
    }

    public function partiallyConsumed(): static
    {
        return $this->state(['status' => MaterialListStatus::PartiallyConsumed, 'delivered_at' => now()->subHour()]);
    }

    public function consumed(): static
    {
        return $this->state(['status' => MaterialListStatus::Consumed, 'delivered_at' => now()->subHour(), 'consumed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => MaterialListStatus::Cancelled, 'cancelled_reason' => 'Ya no se necesita']);
    }
}
