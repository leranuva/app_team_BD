<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class JobSiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'address' => fake()->streetAddress().', New York, NY',
            'client' => fake()->company(),
            'latitude' => 40.75,
            'longitude' => -73.9,
        ];
    }
}
