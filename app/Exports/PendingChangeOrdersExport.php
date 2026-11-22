<?php

namespace App\Exports;

use App\Models\ChangeOrder;
use App\Models\User;
use App\Services\ReportQueryService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PendingChangeOrdersExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(public readonly array $filters) {}

    public function query(): Builder
    {
        return app(ReportQueryService::class)->pendingChangeOrders($this->actor(), $this->filters);
    }

    public function headings(): array
    {
        return ['Code', 'Title', 'Job site', 'Scope', 'Requested by', 'Status', 'Amount', 'Schedule impact (days)', 'Submitted on'];
    }

    /**
     * @param  ChangeOrder  $row
     * @return list<string|int>
     */
    public function map($row): array
    {
        $zone = config('app.business_timezone');

        return [
            $row->code ?? '—',
            $row->title,
            (string) ($row->jobSite?->name ?? ''),
            (string) ($row->jobScope?->name ?? ''),
            (string) ($row->requestedBy?->name ?? ''),
            $row->status->label(),
            (string) BigDecimal::of($row->amount_cents)->dividedBy(100, 2, RoundingMode::HalfUp),
            $row->time_impact_days,
            $row->submitted_at?->setTimezone($zone)->format('Y-m-d H:i') ?? '',
        ];
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->filters['actor_id']);
    }
}
