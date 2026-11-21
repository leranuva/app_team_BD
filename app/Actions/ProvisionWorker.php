<?php

namespace App\Actions;

use App\Enums\LogisticsRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProvisionWorker
{
    public function execute(array $credentials, LogisticsRole $role): User
    {
        return DB::transaction(function () use ($credentials, $role): User {
            $user = User::create($credentials);
            $user->assignRole($role->value);

            return $user;
        });
    }
}
