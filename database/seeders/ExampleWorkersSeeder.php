<?php

namespace Database\Seeders;

use App\Enums\LogisticsRole;
use App\Models\JobSite;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExampleWorkersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $this->call(DatabaseSeeder::class);

        DB::transaction(function (): void {
            $site = JobSite::firstOrCreate(['name' => 'Obra de ejemplo — Queens'], [
                'address' => 'Queens, NY', 'client' => 'Cliente de ejemplo',
                'latitude' => 40.744, 'longitude' => -73.948,
            ]);

            foreach ([
                'supervisor' => LogisticsRole::Supervisor,
                'carpintero' => LogisticsRole::Carpenter,
                'ayudante' => LogisticsRole::Helper,
            ] as $name => $role) {
                $worker = User::withTrashed()->firstOrNew(['email' => "example-{$name}@example.invalid"]);

                if ($worker->exists) {
                    continue;
                }

                $worker->fill([
                    'name' => ucfirst($name).' de ejemplo',
                    'password' => Str::random(48),
                    'primary_job_site_id' => $site->id,
                ])->save();
                $worker->assignRole($role->value);
                $worker->jobSites()->attach($site);
            }
        });
    }
}
