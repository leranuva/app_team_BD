<?php

namespace App\Providers;

use App\Models\ChangeOrder;
use App\Models\JobScope;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Policies\LogisticsRecordPolicy;
use App\Policies\ManagementRolePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('attendance-punch', fn (Request $request) => Limit::perMinute(20)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('shift-options', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('shift-reassign', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        foreach ([ChangeOrder::class, JobScope::class, MaterialList::class, MaterialListItem::class] as $model) {
            Gate::policy($model, LogisticsRecordPolicy::class);
        }

        Gate::policy(Role::class, ManagementRolePolicy::class);
    }
}
