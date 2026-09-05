<?php

namespace App\Console\Commands;

use App\Services\WebhookDeliveryService;
use Illuminate\Console\Command;

/**
 * Story 15.4: process due outbound webhook deliveries.
 */
class ProcessOutboundWebhookDeliveries extends Command
{
    protected $signature = 'webhooks:process-due';

    protected $description = 'Deliver pending and retryable outbound webhooks';

    public function handle(WebhookDeliveryService $deliveries): int
    {
        $result = $deliveries->processDue();

        $this->info(sprintf(
            'Webhook delivery: processed=%d delivered=%d retried=%d quarantined=%d failed=%d',
            $result['processed'],
            $result['delivered'],
            $result['retried'],
            $result['quarantined'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
