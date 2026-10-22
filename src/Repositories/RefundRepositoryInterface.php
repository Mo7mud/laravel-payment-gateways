<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Models\PaymentRefund;

interface RefundRepositoryInterface
{
    public function save(PaymentRefund $refund): PaymentRefund;

    public function findByUuid(string $uuid): ?PaymentRefund;

    public function findByIdempotencyKey(string $paymentReference, string $idempotencyKey): ?PaymentRefund;

    public function findByGatewayReference(string $gateway, string $gatewayRefundReference): ?PaymentRefund;

    /** Oldest pending refund for the payment with exactly this amount and no gateway reference yet. */
    public function findOldestUnmatchedPending(string $paymentReference, int $amountMinor): ?PaymentRefund;

    /** @return list<PaymentRefund> oldest first */
    public function pendingFor(string $paymentReference): array;

    public function sumAmount(string $paymentReference, RefundStatus $status): int;

    /** @return list<string> payment references with pending refunds created before $before */
    public function paymentsWithPendingRefunds(DateTimeInterface $before, int $limit = 100): array;
}
