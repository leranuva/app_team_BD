<?php

namespace App\Http\Middleware;

use App\Enums\LogisticsPermission;
use App\Enums\LogisticsRole;
use App\Models\JobSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $role = $user?->getRoleNames()->first();
        $sites = $user && $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
        ])
            ? Cache::remember(
                'inertia.auth.sites.'.$user->getKey(),
                60,
                fn (): array => JobSite::visibleTo($user)->orderBy('name')->limit(50)->get(['id', 'name'])
                    ->map(fn (JobSite $site): array => ['id' => $site->id, 'name' => $site->name])
                    ->all(),
            )
            : [];

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email']),
                'role' => $role,
                'role_label' => $role === null ? null : LogisticsRole::displayName($role),
                'sites' => $sites,
            ],
        ];
    }
}
