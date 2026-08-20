<?php

namespace App\Console\Commands;

use App\Services\MemberMovementService;
use Illuminate\Console\Command;

/**
 * Story 1.5: apply approved cross-branch movements whose effective date has
 * arrived. Runs on a schedule (see routes/console.php) so the association
 * changes ON the approved date even if no one is logged in at that moment.
 */
class ApplyDueMemberMovements extends Command
{
    protected $signature = 'movements:apply-due';

    protected $description = 'Apply approved member movements whose effective date has arrived (Story 1.5)';

    public function handle(MemberMovementService $service): int
    {
        $applied = $service->applyDueMovements();

        if ($applied > 0) {
            $this->info("Applied {$applied} due member movement(s).");
        } else {
            $this->line('No due member movements to apply.');
        }

        return self::SUCCESS;
    }
}
