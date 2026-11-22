<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\LogisticsPermission;
use App\Models\JobSite;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

trait BuildsReportFilters
{
    public array $filters = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_active && $user->canAny([
            LogisticsPermission::GlobalAccess->value,
            LogisticsPermission::SuperviseSites->value,
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function siteOptions(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        return JobSite::query()->visibleTo($user)->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array{path?: string, url?: string, job_id?: string}  $result
     */
    protected function notifyExport(array $result): void
    {
        if (isset($result['job_id'])) {
            Notification::make()->title('The report is being generated. You will be notified when it is ready.')->success()->send();

            return;
        }

        Notification::make()->title('Report ready')
            ->body('Link valid for 15 minutes: '.$result['url'])
            ->success()
            ->send();
    }
}
