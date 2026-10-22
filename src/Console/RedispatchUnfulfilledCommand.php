<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Console;

use Illuminate\Console\Command;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;

final class RedispatchUnfulfilledCommand extends Command
{
    protected $signature = 'payments:redispatch-unfulfilled
                            {--older-than= : Minutes after payment before PaymentSucceeded is raised again (default: config)}
                            {--limit= : Maximum number of payments per run (default: config)}';

    protected $description = 'Raise PaymentSucceeded again for paid payments the application has not marked fulfilled.';

    public function handle(PaymentService $payments): int
    {
        $minutes = max(1, (int) ($this->option('older-than') ?? config('payment-gateway.fulfilment.older_than_minutes', 10)));
        $limit = max(1, (int) ($this->option('limit') ?? config('payment-gateway.fulfilment.limit', 100)));

        $raised = $payments->redispatchUnfulfilled(now()->subMinutes($minutes)->toImmutable(), $limit);

        $this->info("Redispatched: {$raised}");

        return self::SUCCESS;
    }
}
