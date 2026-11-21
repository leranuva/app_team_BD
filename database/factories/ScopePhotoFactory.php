<?php

namespace Database\Factories;

use App\Models\JobScope;
use App\Models\JobSite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScopePhotoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_scope_id' => fn () => JobScope::create([
                'job_site_id' => JobSite::factory()->create()->id,
                'name' => fake()->words(3, true),
            ])->id,
            'uploaded_by' => User::factory(),
            'path' => 'scopes/fake/test.jpg',
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 204800,
            'taken_at' => now(),
        ];
    }
}
