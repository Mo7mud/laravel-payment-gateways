<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Console;

use Illuminate\Console\Command;
use Mo7mud\LaravelPaymentGateway\Repositories\WebhookEventRepositoryInterface;

final class PruneWebhookEventsCommand extends Command
{
    protected $signature = 'payments:prune-webhooks {--days= : Keep processed/ignored events for this many days (default: config)}';

    protected $description = 'Delete processed and ignored webhook events older than the retention period. Failed events are kept.';

    public function handle(WebhookEventRepositoryInterface $events): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('payment-gateway.webhooks.retention_days', 90)));
        $deleted = $events->prune(now()->subDays($days)->toImmutable());

        $this->info("Deleted {$deleted} webhook events older than {$days} days.");

        return self::SUCCESS;
    }
}
