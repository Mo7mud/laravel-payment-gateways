<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Console;

use Illuminate\Console\Command;
use Mo7mud\LaravelPaymentGateway\Services\PaymentReconciliationService;

final class PaymentReconciliationCommand extends Command
{
    protected $signature = 'payments:reconcile
                            {--limit= : Maximum number of payments to inspect (default: config)}
                            {--minutes= : Minutes without an update before an unscheduled payment is checked (default: config)}';

    protected $description = 'Recover missed webhooks: re-check open payments, expire abandoned ones and settle pending refunds.';

    public function handle(PaymentReconciliationService $reconciliation): int
    {
        $minutes = max(1, (int) ($this->option('minutes') ?? config('payment-gateway.reconciliation.stale_minutes', 15)));
        $limit = max(1, (int) ($this->option('limit') ?? config('payment-gateway.reconciliation.limit', 100)));

        $result = $reconciliation->reconcileStale(now()->subMinutes($minutes)->toImmutable(), $limit);

        $this->info("Examined: {$result->examined}");
        $this->info("Updated: {$result->updated}");
        $this->info("Skipped: {$result->skipped}");
        $this->info("Failed: {$result->failed}");

        foreach ($result->failures as $failure) {
            $this->warn(sprintf('%s: %s: %s', $failure['payment_reference'], $failure['exception'], $failure['message']));
        }

        // Individual failures are retried with back-off; only a run where
        // everything failed (gateway down, bad credentials) is an error.
        return $result->examined > 0 && $result->failed === $result->examined ? self::FAILURE : self::SUCCESS;
    }
}
