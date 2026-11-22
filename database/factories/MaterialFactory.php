<?php

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    protected $model = Material::class;

    public function definition(): array
    {
        return [
            'code' => 'MAT-'.fake()->unique()->numerify('#####'),
            'name' => fake()->words(3, true),
            'unit' => fake()->randomElement(config('materials.units')),
            'category' => fake()->randomElement(config('materials.categories')),
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
