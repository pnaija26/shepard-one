<?php

namespace App\Console\Commands;

use App\Services\GlobalSearchService;
use Illuminate\Console\Command;

/**
 * Story 14.3: retry failed global search index synchronizations.
 */
class ProcessGlobalSearchRetries extends Command
{
    protected $signature = 'search:process-retries';

    protected $description = 'Retry failed global search index synchronizations';

    public function handle(GlobalSearchService $search): int
    {
        $result = $search->processRetries();

        $this->info(sprintf(
            'Retry processing: processed=%d resolved=%d failed=%d',
            $result['processed'],
            $result['resolved'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
