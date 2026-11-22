<?php

namespace App\Exports;

use App\Models\MaterialListItem;
use App\Models\User;
use App\Services\ReportQueryService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LowConsumptionMaterialsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(public readonly array $filters) {}

    public function query(): Builder
    {
        return app(ReportQueryService::class)->lowConsumptionMaterials($this->actor(), $this->filters);
    }

    public function headings(): array
    {
        return ['Material', 'Unit', 'Job site', 'Scope', 'Delivered', 'Used', '% used', 'Last updated'];
    }

    /**
     * @param  MaterialListItem  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $delivered = $this->quantity($row->delivered_quantity);
        $consumed = $this->quantity($row->consumed_quantity ?? '0');
        $percent = BigDecimal::of($consumed)->dividedBy($delivered, 6, RoundingMode::HalfUp)
            ->multipliedBy(100)->toScale(2, RoundingMode::HalfUp);
        $zone = config('app.business_timezone');

        return [
            trim(($row->material?->code ?? '').' '.($row->material?->name ?? '')),
            (string) ($row->material?->unit ?? ''),
            (string) ($row->materialList?->jobSite?->name ?? ''),
            (string) ($row->materialList?->jobScope?->name ?? ''),
            $delivered,
            $consumed,
            (string) $percent,
            $row->updated_at?->setTimezone($zone)->format('Y-m-d H:i') ?? '',
        ];
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->filters['actor_id']);
    }

    private function quantity(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
