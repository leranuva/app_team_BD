<?php

namespace App\Services;

use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Facades\Excel;

class ReportExportService
{
    /**
     * The exports disk is private and rooted at exports/, so a stored path of
     * "{uuid}.xlsx" is the object key exports/{uuid}.xlsx.
     *
     * @param  class-string  $exportClass
     * @param  array<string, mixed>  $filters
     * @return array{path: string, url: string}|array{job_id: string}
     */
    public function exportExcel(string $exportClass, array $filters, ?User $actor = null): array
    {
        $actor ??= Auth::user();
        if (! $actor instanceof User) {
            throw new InvalidArgumentException('A user is required to generate the report.');
        }

        $filters['actor_id'] = $actor->id;
        $export = new $exportClass($filters);
        if (! $export instanceof FromQuery) {
            throw new InvalidArgumentException($exportClass.' tiene que implementar FromQuery.');
        }

        if ($export->query()->count() > (int) config('reports.sync_threshold')) {
            $jobId = (string) Str::uuid();
            GenerateReportExport::dispatch($actor->id, $exportClass, $filters, $jobId);

            return ['job_id' => $jobId];
        }

        return $this->storeExcel($actor, $exportClass, $filters);
    }

    /**
     * @param  class-string  $exportClass
     * @param  array<string, mixed>  $filters
     * @return array{path: string, url: string}
     */
    public function storeExcel(User $actor, string $exportClass, array $filters): array
    {
        $filters['actor_id'] = $actor->id;
        $path = (string) Str::uuid().'.xlsx';
        Excel::store(new $exportClass($filters), $path, 'exports');

        return $this->remember($actor, $path, 'excel', $exportClass, $filters);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{path: string, url: string}
     */
    public function exportPdf(string $view, array $data, string $filename, ?User $actor = null): array
    {
        $actor ??= Auth::user();
        if (! $actor instanceof User) {
            throw new InvalidArgumentException('A user is required to generate the report.');
        }

        $path = (string) Str::uuid().'.pdf';
        Storage::disk('exports')->put($path, Pdf::loadView($view, $data)->output());

        return $this->remember($actor, $path, 'pdf', $view, ['filename' => $filename]);
    }

    public function temporaryUrl(string $path): string
    {
        return Storage::disk('exports')->temporaryUrl(
            $path,
            now()->addMinutes((int) config('reports.url_minutes')),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{path: string, url: string}
     */
    private function remember(User $actor, string $path, string $type, string $reportClass, array $filters): array
    {
        ReportExport::query()->create([
            'user_id' => $actor->id,
            'path' => $path,
            'type' => $type,
            'report_class' => $reportClass,
            'filters' => $filters,
            'expires_at' => now()->addDays((int) config('reports.retention_days')),
        ]);

        return ['path' => $path, 'url' => $this->temporaryUrl($path)];
    }
}
