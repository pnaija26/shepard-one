<?php

namespace App\Console\Commands;

use App\Services\ExternalAdapterRuntimeService;
use Illuminate\Console\Command;

/**
 * Story 15.5: process due external adapter operations.
 */
class ProcessExternalAdapterOperations extends Command
{
    protected $signature = 'adapters:process-due';

    protected $description = 'Process pending and retryable external adapter operations';

    public function handle(ExternalAdapterRuntimeService $runtime): int
    {
        $result = $runtime->processDue();

        $this->info(sprintf(
            'Adapter operations: processed=%d completed=%d retried=%d cancelled=%d failed=%d',
            $result['processed'],
            $result['completed'],
            $result['retried'],
            $result['cancelled'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
