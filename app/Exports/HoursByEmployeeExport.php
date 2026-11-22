<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class HoursByEmployeeExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array<string, string>>  $rows
     */
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Worker', 'Role', 'Job site', 'Week', 'Regular hours', 'Overtime', 'Double time', 'Total', 'Cost (USD)'];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function map($row): array
    {
        return [
            $row['worker'],
            $row['role'],
            $row['site'],
            $row['week'],
            $row['regular_hours'],
            $row['overtime_hours'],
            $row['double_time_hours'],
            $row['total_hours'],
            $row['cost'],
        ];
    }
}
