<?php

namespace App\Console\Commands;

use App\Services\GlobalSearchService;
use Illuminate\Console\Command;

/**
 * Story 14.3: rebuild the permission-scoped global search index.
 */
class RebuildGlobalSearchIndex extends Command
{
    protected $signature = 'search:rebuild-index';

    protected $description = 'Rebuild the global church search index from source records';

    public function handle(GlobalSearchService $search): int
    {
        $result = $search->rebuildIndex();

        $this->info(sprintf(
            'Index rebuilt: indexed=%d removed=%d failures=%d',
            $result['indexed'],
            $result['removed'],
            $result['failures'],
        ));

        return self::SUCCESS;
    }
}
