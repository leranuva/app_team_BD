<?php

namespace App\Services;

use App\Enums\ChangeOrderStatus;
use App\Enums\MaterialListStatus;
use App\Models\Attendance;
use App\Models\ChangeOrder;
use App\Models\JobSite;
use App\Models\MaterialList;
use App\Models\MaterialListItem;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportQueryService
{
    public function __construct(private readonly AttendanceCalculatorService $calculator) {}

    /**
     * Closed and corrected attendances in the range, grouped by worker, site and week.
     * Minutes stay integers until the hour conversion, so SQLite and PostgreSQL match.
     *
     * @return Collection<int, array{
     *     worker: string, role: string, site: string, week: string,
     *     regular_hours: string, overtime_hours: string, double_time_hours: string,
     *     total_hours: string, cost: string
     * }>
     */
    public function hoursByEmployee(User $actor, CarbonInterface $from, CarbonInterface $until, ?int $jobSiteId = null, ?int $userId = null): Collection
    {
        $zone = config('app.business_timezone');
        $start = $from->setTimezone($zone)->startOfDay();
        $end = $until->setTimezone($zone)->endOfDay();
        if ($end->lessThan($start)) {
            return collect();
        }

        $rows = Attendance::query()->visibleTo($actor)
            ->whereNotNull('clocked_out_at')
            ->where('clocked_in_at', '>=', $start->utc())
            ->where('clocked_in_at', '<=', $end->utc())
            ->when($jobSiteId !== null, fn ($query) => $query->where('job_site_id', $jobSiteId))
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->with(['user.roles', 'jobSite'])
            ->orderBy('clocked_in_at')
            ->get();

        return $rows->groupBy(function (Attendance $attendance) use ($zone): string {
            $week = $this->calculator->weekStart($attendance->clocked_in_at->setTimezone($zone))->toDateString();

            return $attendance->user_id.'|'.$attendance->job_site_id.'|'.$week;
        })->map(function (Collection $group) use ($zone): array {
            /** @var Attendance $first */
            $first = $group->first();
            $regular = (int) $group->sum(fn (Attendance $row): int => (int) ($row->regular_minutes ?? 0));
            $overtime = (int) $group->sum(fn (Attendance $row): int => (int) ($row->overtime_minutes ?? 0));
            $doubleTime = (int) $group->sum(fn (Attendance $row): int => (int) ($row->double_time_minutes ?? 0));
            $rate = $first->user?->hourly_rate;

            return [
                'worker' => $first->user?->name ?? '',
                'role' => $first->user?->getRoleNames()->sort()->implode(', ') ?? '',
                'site' => $first->jobSite?->name ?? '',
                'week' => $this->calculator->weekStart($first->clocked_in_at->setTimezone($zone))->toDateString(),
                'regular_hours' => $this->hours($regular),
                'overtime_hours' => $this->hours($overtime),
                'double_time_hours' => $this->hours($doubleTime),
                'total_hours' => $this->hours($regular + $overtime + $doubleTime),
                'cost' => $rate === null || $rate === ''
                    ? '—'
                    : $this->calculator->estimateGrossPay([
                        'regular_minutes' => $regular,
                        'overtime_minutes' => $overtime,
                        'double_time_minutes' => $doubleTime,
                    ], (string) $rate),
            ];
        })->sortBy(['worker', 'week', 'site'])->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function lowConsumptionMaterials(User $actor, array $filters): Builder
    {
        $threshold = (string) config('reports.low_consumption_threshold');
        $bounds = $this->dateBounds($filters);
        $siteId = $this->nullableInt($filters['job_site_id'] ?? null);
        $scopeId = $this->nullableInt($filters['job_scope_id'] ?? null);

        return MaterialListItem::query()->visibleTo($actor)
            ->where('delivered_quantity', '>', 0)
            ->whereRaw('COALESCE(consumed_quantity, 0) < delivered_quantity * ?', [$threshold])
            ->whereHas('materialList', function (Builder $lists) use ($bounds, $siteId, $scopeId): void {
                $lists->whereIn('status', [
                    MaterialListStatus::Delivered,
                    MaterialListStatus::PartiallyConsumed,
                    MaterialListStatus::Consumed,
                ])->when($siteId !== null, fn (Builder $query) => $query->where('job_site_id', $siteId))
                    ->when($scopeId !== null, fn (Builder $query) => $query->where('job_scope_id', $scopeId))
                    ->when(is_array($bounds), fn (Builder $query) => $query->whereBetween('delivered_at', $bounds))
                    ->when($bounds === false, fn (Builder $query) => $query->whereRaw('1 = 0'));
            })
            ->with(['material', 'materialList.jobSite', 'materialList.jobScope'])
            ->orderBy('id');
    }

    /**
     * @return Collection<int, array{material: string, percent: string, site: string}>
     */
    public function lowestConsumption(User $actor, int $limit = 5): Collection
    {
        return $this->lowConsumptionMaterials($actor, [])
            ->reorder()
            ->orderByRaw('COALESCE(consumed_quantity, 0) / delivered_quantity')
            ->limit($limit)
            ->get()
            ->map(function (MaterialListItem $item): array {
                $delivered = BigDecimal::of((string) $item->delivered_quantity);
                $percent = BigDecimal::of((string) ($item->consumed_quantity ?? '0'))
                    ->dividedBy($delivered, 6, RoundingMode::HalfUp)
                    ->multipliedBy(100)->toScale(2, RoundingMode::HalfUp);

                return [
                    'material' => trim(($item->material?->code ?? '').' '.($item->material?->name ?? '')),
                    'percent' => (string) $percent,
                    'site' => (string) ($item->materialList?->jobSite?->name ?? ''),
                ];
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function pendingChangeOrders(User $actor, array $filters): Builder
    {
        $bounds = $this->dateBounds($filters);
        $siteId = $this->nullableInt($filters['job_site_id'] ?? null);
        $status = $filters['status'] ?? null;
        $allowed = [ChangeOrderStatus::Submitted->value, ChangeOrderStatus::UnderReview->value];

        return ChangeOrder::query()->visibleTo($actor)
            ->pendingReview()
            ->when($siteId !== null, fn (Builder $query) => $query->where('job_site_id', $siteId))
            ->when(is_string($status) && $status !== '', function (Builder $query) use ($status, $allowed): void {
                in_array($status, $allowed, true)
                    ? $query->where('status', $status)
                    : $query->whereRaw('1 = 0');
            })
            ->when(is_array($bounds), fn (Builder $query) => $query->whereBetween('submitted_at', $bounds))
            ->when($bounds === false, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->with(['jobSite', 'jobScope', 'requestedBy'])
            ->orderBy('submitted_at');
    }

    public function pendingMaterialListCount(User $actor): int
    {
        return MaterialList::query()->visibleTo($actor)
            ->whereIn('status', [MaterialListStatus::Pending, MaterialListStatus::Submitted])
            ->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     sites: list<array<string, string|null>>,
     *     totals: array{budget: string, real_cost: string, difference: string},
     *     unpriced_items: int,
     *     range_label: string,
     *     generated_at: CarbonImmutable
     * }
     */
    public function costVsBudget(User $actor, array $filters): array
    {
        $bounds = $this->dateBounds($filters);
        $siteId = $this->nullableInt($filters['job_site_id'] ?? null);
        $sites = JobSite::query()->visibleTo($actor)
            ->when($siteId !== null, fn (Builder $query) => $query->whereKey($siteId))
            ->orderBy('name')
            ->get();
        $ids = $sites->modelKeys();
        $orders = $ids === [] ? collect() : ChangeOrder::query()
            ->withoutGlobalScope(LogisticsVisibilityScope::class)
            ->approved()
            ->whereIn('job_site_id', $ids)
            ->when(is_array($bounds), fn (Builder $query) => $query->whereBetween('approved_at', $bounds))
            ->when($bounds === false, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->get(['job_site_id', 'amount_cents'])
            ->groupBy('job_site_id');
        $items = $ids === [] ? collect() : MaterialListItem::query()
            ->withoutGlobalScope(LogisticsVisibilityScope::class)
            ->whereHas('materialList', function (Builder $lists) use ($ids, $bounds): void {
                $lists->withoutGlobalScope(LogisticsVisibilityScope::class)
                    ->whereIn('job_site_id', $ids)
                    ->whereIn('status', [
                        MaterialListStatus::Delivered,
                        MaterialListStatus::PartiallyConsumed,
                        MaterialListStatus::Consumed,
                    ])
                    ->when(is_array($bounds), fn (Builder $query) => $query->whereBetween('delivered_at', $bounds))
                    ->when($bounds === false, fn (Builder $query) => $query->whereRaw('1 = 0'));
            })
            ->with('materialList:id,job_site_id')
            ->get(['id', 'material_list_id', 'delivered_quantity', 'unit_cost_cents']);

        $unpriced = 0;
        $materialCents = [];
        foreach ($items as $item) {
            $owner = $item->materialList?->job_site_id;
            if ($owner === null) {
                continue;
            }
            if ($item->unit_cost_cents === null) {
                $unpriced++;

                continue;
            }
            $materialCents[$owner] = ($materialCents[$owner] ?? BigDecimal::zero())
                ->plus(BigDecimal::of((string) $item->delivered_quantity)->multipliedBy($item->unit_cost_cents));
        }

        $rows = [];
        $budgetTotal = BigDecimal::zero();
        $realTotal = BigDecimal::zero();
        foreach ($sites as $site) {
            $budget = BigDecimal::of((string) ($site->base_budget ?? '0'))->toScale(2, RoundingMode::HalfUp);
            $cents = ($materialCents[$site->id] ?? BigDecimal::zero())
                ->plus((int) ($orders->get($site->id)?->sum('amount_cents') ?? 0));
            $real = $cents->dividedBy(100, 2, RoundingMode::HalfUp);
            $hasBudget = $budget->isGreaterThan(0);
            $rows[] = [
                'name' => $site->name,
                'client' => $site->client,
                'budget' => $hasBudget ? (string) $budget : null,
                'real_cost' => (string) $real,
                'difference' => (string) $real->minus($budget),
                'percent_executed' => $hasBudget
                    ? (string) $real->dividedBy($budget, 6, RoundingMode::HalfUp)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp)
                    : null,
            ];
            $budgetTotal = $budgetTotal->plus($budget);
            $realTotal = $realTotal->plus($real);
        }

        $zone = config('app.business_timezone');

        return [
            'sites' => $rows,
            'totals' => [
                'budget' => (string) $budgetTotal->toScale(2, RoundingMode::HalfUp),
                'real_cost' => (string) $realTotal->toScale(2, RoundingMode::HalfUp),
                'difference' => (string) $realTotal->minus($budgetTotal)->toScale(2, RoundingMode::HalfUp),
            ],
            'unpriced_items' => $unpriced,
            'range_label' => $bounds === null
                ? 'Todo el historial'
                : $bounds[0]->setTimezone($zone)->toDateString().' — '.$bounds[1]->setTimezone($zone)->toDateString(),
            'generated_at' => CarbonImmutable::now($zone),
        ];
    }

    /**
     * @return Collection<int, array{site: string, regular_hours: string, overtime_hours: string}>
     */
    public function hoursThisWeekBySite(User $actor): Collection
    {
        $start = $this->calculator->weekStart(now());

        return $this->hoursByEmployee($actor, $start, $start->addDays(6), null, null)
            ->groupBy('site')
            ->map(fn (Collection $group, string $site): array => [
                'site' => $site,
                'regular_hours' => $this->sumMoney($group, 'regular_hours'),
                'overtime_hours' => $this->sumMoney($group, 'overtime_hours'),
            ])->values();
    }

    private function hours(int $minutes): string
    {
        return (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null|false
     */
    private function dateBounds(array $filters): array|false|null
    {
        $from = $filters['from'] ?? null;
        $until = $filters['until'] ?? null;
        if (! is_string($from) || $from === '' || ! is_string($until) || $until === '') {
            return null;
        }

        $zone = config('app.business_timezone');
        $start = CarbonImmutable::parse($from, $zone)->startOfDay();
        $end = CarbonImmutable::parse($until, $zone)->endOfDay();

        return $end->lessThan($start) ? false : [$start, $end];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  Collection<int, array<string, string>>  $rows
     */
    private function sumMoney(Collection $rows, string $key): string
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) {
            $total = $total->plus($row[$key]);
        }

        return (string) $total->toScale(2, RoundingMode::HalfUp);
    }
}
