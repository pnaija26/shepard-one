<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\ReportExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Story 13.4: queued, retryable report export generation.
 */
class GenerateReportExportJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public function __construct(
        public ReportExport $export,
    ) {
        $this->tries = (int) config('report_exports.max_attempts', 3);
    }

    public function handle(ReportExportService $exports): void
    {
        $exports->processQueued($this->export);
    }
}
