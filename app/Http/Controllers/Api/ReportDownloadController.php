<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, ReportExport $reportExport, ReportExportService $exports): JsonResponse
    {
        $available = $reportExport->expires_at->isFuture()
            && Storage::disk('exports')->exists($reportExport->path);

        if (! $available) {
            abort(404);
        }

        if ($reportExport->user_id !== $request->user()?->id) {
            abort(403);
        }

        return response()->json(['url' => $exports->temporaryUrl($reportExport->path)]);
    }
}
