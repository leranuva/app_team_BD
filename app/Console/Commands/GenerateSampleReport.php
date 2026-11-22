<?php

namespace App\Console\Commands;

use App\Exports\HoursByEmployeeExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class GenerateSampleReport extends Command
{
    protected $signature = 'reports:sample';

    protected $description = 'Write a fixture Excel workbook and a fixture PDF to the local disk';

    public function handle(): int
    {
        $generatedAt = now(config('app.business_timezone'));
        $rows = collect([[
            'worker' => 'Ana Torres',
            'role' => 'Carpintero',
            'site' => 'Queens',
            'week' => '2026-11-23',
            'regular_hours' => '8.00',
            'overtime_hours' => '1.00',
            'double_time_hours' => '0.00',
            'total_hours' => '9.00',
            'cost' => '—',
        ]]);

        Excel::store(new HoursByEmployeeExport($rows), 'reports-sample/sample-hours.xlsx', 'local');
        Storage::disk('local')->put(
            'reports-sample/sample-hours.pdf',
            Pdf::loadView('reports.sample', ['rows' => $rows, 'generatedAt' => $generatedAt])->output(),
        );

        $this->info(Storage::disk('local')->path('reports-sample/sample-hours.xlsx'));
        $this->info(Storage::disk('local')->path('reports-sample/sample-hours.pdf'));

        return self::SUCCESS;
    }
}
