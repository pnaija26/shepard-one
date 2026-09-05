<?php

namespace App\Console\Commands;

use App\Services\OperationsMonitoringService;
use Illuminate\Console\Command;

/**
 * Story 15.6: collect operations telemetry and evaluate alert thresholds.
 */
class CollectOperationsTelemetry extends Command
{
    protected $signature = 'operations:collect-telemetry';

    protected $description = 'Capture operations telemetry snapshots and evaluate alert thresholds';

    public function handle(OperationsMonitoringService $operations): int
    {
        $result = $operations->collectTelemetry();

        $this->info(sprintf(
            'Operations telemetry: snapshots=%d alerts=%d',
            $result['snapshots'],
            $result['alerts'],
        ));

        return self::SUCCESS;
    }
}
