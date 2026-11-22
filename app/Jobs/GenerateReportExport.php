<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ReportReadyNotification;
use App\Services\ReportExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public int $userId,
        public string $exportClass,
        public array $filters,
        public string $jobId,
    ) {
        $this->onQueue('reports');
    }

    public function handle(ReportExportService $exports): void
    {
        $user = User::query()->findOrFail($this->userId);
        $stored = $exports->storeExcel($user, $this->exportClass, $this->filters);
        $user->notify(new ReportReadyNotification($stored['url'], 'Excel report'));
    }
}
