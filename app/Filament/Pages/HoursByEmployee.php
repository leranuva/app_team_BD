<?php

namespace App\Filament\Pages;

use App\Exports\HoursByEmployeeExport;
use App\Models\Attendance;
use App\Models\JobSite;
use App\Models\User;
use App\Services\AttendanceCalculatorService;
use App\Services\ReportQueryService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class HoursByEmployee extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Hours by employee';

    protected static ?string $title = 'Hours by employee';

    protected static ?int $navigationSort = 10;

    /**
     * @var array{from: string, until: string, job_site_id: int|string|null, user_id: int|string|null}
     */
    public array $filters = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('viewAny', Attendance::class);
    }

    public function mount(): void
    {
        $this->filters = $this->defaultFilters();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([
                DatePicker::make('from')->label('From')->required()->native(false),
                DatePicker::make('until')->label('To')->required()->native(false)->afterOrEqual('from'),
                Select::make('job_site_id')->label('Job site')->placeholder('All visible job sites')
                    ->options(fn (): array => $this->siteOptions())->searchable(),
                Select::make('user_id')->label('Worker')->placeholder('All')
                    ->options(fn (): array => $this->workerOptions())->searchable(),
            ])->statePath('filters')->columns(4)->live(),
            View::make('filament.reports.hours-by-employee')
                ->viewData(fn (): array => ['rows' => $this->rows()]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Download Excel')
                ->action(function (): ?StreamedResponse {
                    $range = $this->range();
                    if ($range === null) {
                        Notification::make()->title('Enter a valid date range.')->danger()->send();

                        return null;
                    }

                    $workbook = Excel::raw(new HoursByEmployeeExport($this->rows()), ExcelWriter::XLSX);

                    return response()->streamDownload(
                        static function () use ($workbook): void {
                            echo $workbook;
                        },
                        'hours-by-employee.xlsx',
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                    );
                }),
        ];
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    public function rows(): Collection
    {
        $actor = Auth::user();
        $range = $this->range();
        if ($actor === null || $range === null) {
            return collect();
        }

        return app(ReportQueryService::class)->hoursByEmployee(
            $actor,
            $range[0],
            $range[1],
            $this->nullableInt($this->filters['job_site_id'] ?? null),
            $this->nullableInt($this->filters['user_id'] ?? null),
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function range(): ?array
    {
        $from = $this->filters['from'] ?? null;
        $until = $this->filters['until'] ?? null;
        if (! is_string($from) || ! is_string($until) || $from === '' || $until === '') {
            return null;
        }

        $zone = config('app.business_timezone');
        $start = CarbonImmutable::parse($from, $zone)->startOfDay();
        $end = CarbonImmutable::parse($until, $zone)->endOfDay();

        return $end->lessThan($start) ? null : [$start, $end];
    }

    /**
     * @return array{from: string, until: string, job_site_id: null, user_id: null}
     */
    private function defaultFilters(): array
    {
        $start = app(AttendanceCalculatorService::class)->weekStart(now());

        return [
            'from' => $start->toDateString(),
            'until' => $start->addDays(6)->toDateString(),
            'job_site_id' => null,
            'user_id' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function siteOptions(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return [];
        }

        return JobSite::query()->visibleTo($user)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    private function workerOptions(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return [];
        }

        return User::query()->workersVisibleTo($user)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
