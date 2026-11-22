<?php

namespace App\Providers;

use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Policies\LogisticsRecordPolicy;
use App\Policies\ManagementRolePolicy;
use App\Policies\MaterialListPolicy;
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

        RateLimiter::for('logistics-read', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('scope-photo-read', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('scope-photo-write', fn (Request $request) => Limit::perMinute(30)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('materials-read', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('change-order-read', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('change-order-write', fn (Request $request) => Limit::perMinute(30)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('material-list-read', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        RateLimiter::for('material-list-write', fn (Request $request) => Limit::perMinute(30)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));

        Gate::policy(MaterialList::class, MaterialListPolicy::class);
        Gate::policy(MaterialListItem::class, LogisticsRecordPolicy::class);

        Gate::policy(Role::class, ManagementRolePolicy::class);
    }
}
