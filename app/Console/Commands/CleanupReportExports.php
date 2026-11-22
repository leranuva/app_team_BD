<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupReportExports extends Command
{
    protected $signature = 'reports:cleanup';

    protected $description = 'Delete report files older than the retention window';

    public function handle(): int
    {
        $disk = Storage::disk('exports');
        $removed = 0;

        ReportExport::query()->where('expires_at', '<=', now())->orderBy('id')->each(function (ReportExport $export) use ($disk, &$removed): void {
            $disk->delete($export->path);
            $export->delete();
            $removed++;
        });

        $this->info('Reportes eliminados: '.$removed);

        return self::SUCCESS;
    }
}
