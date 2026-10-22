<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Console;

use Illuminate\Console\Command;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentGatewayExceptionInterface;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;

final class ResolveRefundCommand extends Command
{
    protected $signature = 'payments:resolve-refund
                            {refund : The refund id (payment_refunds.uuid)}
                            {status : succeeded or failed}
                            {--reference= : The gateway refund id, if known}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Resolve a pending refund manually after checking the gateway dashboard.';

    public function handle(PaymentService $payments): int
    {
        $status = RefundStatus::tryFrom(strtolower((string) $this->argument('status')));
        if ($status !== RefundStatus::SUCCEEDED && $status !== RefundStatus::FAILED) {
            $this->error('Status must be "succeeded" or "failed".');

            return self::INVALID;
        }

        if (!$this->option('force') && !$this->confirm('Only continue after confirming the outcome in the gateway dashboard. Continue?')) {
            return self::FAILURE;
        }

        try {
            $refund = $payments->resolveRefund((string) $this->argument('refund'), $status, $this->option('reference') ?: null);
        } catch (PaymentGatewayExceptionInterface $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Refund %s of payment %s marked %s.', $refund->uuid, $refund->payment_reference, $status->value));

        return self::SUCCESS;
    }
}
