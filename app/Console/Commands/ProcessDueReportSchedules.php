<?php

namespace App\Console\Commands;

use App\Services\ReportScheduleService;
use Illuminate\Console\Command;

/**
 * Story 13.5: process due scheduled report distributions.
 */
class ProcessDueReportSchedules extends Command
{
    protected $signature = 'reports:process-due';

    protected $description = 'Generate and distribute due scheduled reports';

    public function handle(ReportScheduleService $schedules): int
    {
        $result = $schedules->processDueSchedules();

        $this->info(sprintf(
            'Processed %d schedule(s): %d completed, %d failed, %d skipped.',
            $result['processed'],
            $result['completed'],
            $result['failed'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
