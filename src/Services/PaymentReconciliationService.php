<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\ReconciliationResult;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\PaymentRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Repositories\RefundRepositoryInterface;
use Throwable;

/**
 * Batch recovery for missed webhooks:
 *  - open payments are re-checked with exponential back-off, so one broken
 *    payment cannot starve the rest of the batch;
 *  - unpaid payments past their checkout expiry become EXPIRED;
 *  - INITIATING rows from interrupted requests become CREATION_FAILED;
 *  - pending refunds are settled from the gateway's refunded totals.
 */
final class PaymentReconciliationService
{
    private readonly RefundRepositoryInterface $refunds;

    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly PaymentService $paymentService,
        ?RefundRepositoryInterface $refunds = null,
        private readonly int $maxAgeDays = 30,
    ) {
        $this->refunds = $refunds ?? new InMemoryRefundRepository;
    }

    public function reconcileStale(
        ?DateTimeInterface $staleBefore = null,
        int $limit = 100,
    ): ReconciliationResult {
        $now = new DateTimeImmutable;
        $staleBefore ??= $now->modify('-15 minutes');
        $createdAfter = $this->maxAgeDays > 0 ? $now->modify('-'.$this->maxAgeDays.' days') : null;

        $examined = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $failures = [];

        $candidates = $this->payments->findReconciliationCandidates(
            staleBefore: $staleBefore,
            statuses: [PaymentStatus::INITIATING, PaymentStatus::PENDING, PaymentStatus::PROCESSING],
            limit: $limit,
            dueAt: $now,
            createdAfter: $createdAfter,
        );

        foreach ($candidates as $payment) {
            $examined++;
            $reference = (string) $payment->reference;
            $before = $payment->status;

            try {
                if ($before === PaymentStatus::INITIATING) {
                    $this->paymentService->failStaleInitiation($reference) ? $updated++ : $skipped++;

                    continue;
                }

                $this->paymentService->reconcilePayment(new PaymentStatusRequest($reference));
                $this->paymentService->expireIfDue($reference, $now);

                $after = $this->payments->findByReference($reference);
                $after === null || $after->status === $before ? $skipped++ : $updated++;
            } catch (Throwable $e) {
                $failed++;
                $failures[] = [
                    'payment_reference' => $reference,
                    'exception' => $e::class,
                    'message' => mb_substr($e->getMessage(), 0, 300),
                ];

                // No answer from the gateway: still close the payment once its
                // checkout has expired, so it does not stay "pending" forever.
                try {
                    $this->paymentService->expireIfDue($reference, $now);
                } catch (Throwable) {
                    // Reported above.
                }
            } finally {
                $this->reschedule($reference, $now);
            }
        }

        foreach ($this->refunds->paymentsWithPendingRefunds($now->modify('-10 minutes'), $limit) as $reference) {
            $examined++;

            try {
                $this->paymentService->reconcilePayment(new PaymentStatusRequest($reference));
                $skipped++;
            } catch (Throwable $e) {
                $failed++;
                $failures[] = [
                    'payment_reference' => $reference,
                    'exception' => $e::class,
                    'message' => mb_substr($e->getMessage(), 0, 300),
                ];
            }
        }

        return new ReconciliationResult($examined, $updated, $skipped, $failed, $failures);
    }

    private function reschedule(string $reference, DateTimeImmutable $now): void
    {
        try {
            $this->paymentService->scheduleNextReconciliation($reference, $now);
        } catch (Throwable) {
            // Scheduling is best effort; the next run picks the payment up again.
        }
    }
}
