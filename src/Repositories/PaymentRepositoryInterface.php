<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Models\Payment;

interface PaymentRepositoryInterface
{
    public function findByReference(string $reference): ?Payment;

    /** Must be called inside a transaction; locks the row until commit. */
    public function findByReferenceForUpdate(string $reference): ?Payment;

    public function findByGatewayReference(string $gateway, string $gatewayReference): ?Payment;

    public function findByGatewayTransactionId(string $gateway, string $gatewayTransactionId): ?Payment;

    public function findByGatewayOrderReference(string $gateway, string $gatewayOrderReference): ?Payment;

    /**
     * Payments due for reconciliation: next_reconcile_at <= $dueAt, or never
     * scheduled and last updated at or before $staleBefore. Oldest first.
     *
     * @param list<PaymentStatus> $statuses
     * @return iterable<int, Payment>
     */
    public function findReconciliationCandidates(
        DateTimeInterface $staleBefore,
        array $statuses = [PaymentStatus::PENDING, PaymentStatus::PROCESSING],
        int $limit = 100,
        ?DateTimeInterface $dueAt = null,
        ?DateTimeInterface $createdAfter = null,
    ): iterable;

    /**
     * Payments that await fulfilment (PaymentStatus::awaitsFulfilment()) and
     * were not marked fulfilled: paid at or before $paidBefore, dispatched
     * fewer than $maxDispatches times, and next_fulfilment_dispatch_at empty
     * or <= $dueAt. Oldest payment first.
     *
     * @return iterable<int, Payment>
     */
    public function findUnfulfilledCandidates(
        DateTimeInterface $paidBefore,
        DateTimeInterface $dueAt,
        int $maxDispatches,
        int $limit = 100,
    ): iterable;

    public function save(Payment $payment): Payment;
}
