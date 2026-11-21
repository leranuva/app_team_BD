<?php

namespace Database\Seeders;

use App\Enums\LogisticsPermission;
use App\Enums\LogisticsRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach (LogisticsPermission::cases() as $permission) {
                Permission::findOrCreate($permission->value, 'web');
            }

            foreach (LogisticsRole::cases() as $role) {
                $permissions = match ($role) {
                    LogisticsRole::SuperAdmin => LogisticsPermission::cases(),
                    LogisticsRole::Administrator => [LogisticsPermission::GlobalAccess, LogisticsPermission::ManageSites],
                    LogisticsRole::Supervisor => [LogisticsPermission::SuperviseSites],
                    LogisticsRole::Carpenter, LogisticsRole::Helper => [LogisticsPermission::ViewAssignments],
                };

                Role::findOrCreate($role->value, 'web')->syncPermissions(
                    array_map(fn (LogisticsPermission $permission): string => $permission->value, $permissions),
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
