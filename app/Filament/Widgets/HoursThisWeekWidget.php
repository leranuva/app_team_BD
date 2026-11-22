<?php

namespace App\Filament\Widgets;

use App\Models\Attendance;
use App\Models\User;
use App\Services\ReportQueryService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class HoursThisWeekWidget extends Widget
{
    protected static ?int $sort = 20;

    protected string $view = 'filament.widgets.hours-this-week';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('viewAny', Attendance::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();

        return [
            'rows' => $user instanceof User
                ? app(ReportQueryService::class)->hoursThisWeekBySite($user)
                : collect(),
        ];
    }
}
