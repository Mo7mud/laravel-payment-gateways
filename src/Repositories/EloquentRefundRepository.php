<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Models\PaymentRefund;

final class EloquentRefundRepository implements RefundRepositoryInterface
{
    public function save(PaymentRefund $refund): PaymentRefund
    {
        $refund->save();

        return $refund;
    }

    public function findByUuid(string $uuid): ?PaymentRefund
    {
        return PaymentRefund::query()->where('uuid', $uuid)->first();
    }

    public function findByIdempotencyKey(string $paymentReference, string $idempotencyKey): ?PaymentRefund
    {
        return PaymentRefund::query()
            ->where('payment_reference', $paymentReference)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function findByGatewayReference(string $gateway, string $gatewayRefundReference): ?PaymentRefund
    {
        return PaymentRefund::query()
            ->where('gateway', $gateway)
            ->where('gateway_refund_reference', $gatewayRefundReference)
            ->first();
    }

    public function findOldestUnmatchedPending(string $paymentReference, int $amountMinor): ?PaymentRefund
    {
        return PaymentRefund::query()
            ->where('payment_reference', $paymentReference)
            ->where('status', RefundStatus::PENDING->value)
            ->where('amount_minor', $amountMinor)
            ->whereNull('gateway_refund_reference')
            ->orderBy('id')
            ->first();
    }

    public function pendingFor(string $paymentReference): array
    {
        return PaymentRefund::query()
            ->where('payment_reference', $paymentReference)
            ->where('status', RefundStatus::PENDING->value)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function sumAmount(string $paymentReference, RefundStatus $status): int
    {
        return (int) PaymentRefund::query()
            ->where('payment_reference', $paymentReference)
            ->where('status', $status->value)
            ->sum('amount_minor');
    }

    public function paymentsWithPendingRefunds(DateTimeInterface $before, int $limit = 100): array
    {
        return PaymentRefund::query()
            ->select('payment_reference')
            ->where('status', RefundStatus::PENDING->value)
            ->where('created_at', '<=', $before)
            ->groupBy('payment_reference')
            ->orderByRaw('MIN(id)')
            ->limit($limit)
            ->pluck('payment_reference')
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }
}
