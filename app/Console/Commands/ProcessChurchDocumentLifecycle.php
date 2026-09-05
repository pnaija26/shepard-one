<?php

namespace App\Console\Commands;

use App\Services\ChurchDocumentService;
use Illuminate\Console\Command;

/**
 * Story 14.2: archive expired documents and preserve legal holds.
 */
class ProcessChurchDocumentLifecycle extends Command
{
    protected $signature = 'documents:process-lifecycle';

    protected $description = 'Archive expired church documents while preserving legal holds and version history';

    public function handle(ChurchDocumentService $documents): int
    {
        $result = $documents->processLifecycle();

        $this->info(sprintf(
            'Processed lifecycle: archived=%d skipped_hold=%d',
            $result['archived'],
            $result['skipped_hold'],
        ));

        return self::SUCCESS;
    }
}
