<?php

namespace App\Console\Commands;

use App\Actions\ProvisionWorker;
use App\Enums\LogisticsRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateWorker extends Command
{
    protected $signature = 'logistics:create-worker';

    protected $description = 'Create an internal account and assign an operations role';

    public function handle(ProvisionWorker $provisionWorker): int
    {
        $roles = collect(LogisticsRole::cases())->keyBy(fn (LogisticsRole $role) => $role->label());
        $role = $roles->get($this->choice('Role', $roles->keys()->all()));
        $validator = Validator::make([
            'name' => $this->ask('Name'),
            'email' => strtolower(trim((string) $this->ask('Email'))),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $provisionWorker->execute($validator->validated(), $role);
        $this->info('Account created.');

        return self::SUCCESS;
    }
}
