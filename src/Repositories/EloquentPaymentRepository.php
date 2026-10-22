<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Models\Payment;

final class EloquentPaymentRepository implements PaymentRepositoryInterface
{
    public function findByReference(string $reference): ?Payment
    {
        return Payment::query()->where('reference', $reference)->first();
    }

    public function findByReferenceForUpdate(string $reference): ?Payment
    {
        return Payment::query()->where('reference', $reference)->lockForUpdate()->first();
    }

    public function findByGatewayReference(string $gateway, string $gatewayReference): ?Payment
    {
        return Payment::query()
            ->where('gateway', $gateway)
            ->where('gateway_reference', $gatewayReference)
            ->first();
    }

    public function findByGatewayTransactionId(string $gateway, string $gatewayTransactionId): ?Payment
    {
        return Payment::query()
            ->where('gateway', $gateway)
            ->where('gateway_transaction_id', $gatewayTransactionId)
            ->first();
    }

    public function findByGatewayOrderReference(string $gateway, string $gatewayOrderReference): ?Payment
    {
        return Payment::query()
            ->where('gateway', $gateway)
            ->where('gateway_order_reference', $gatewayOrderReference)
            ->first();
    }

    public function findReconciliationCandidates(
        DateTimeInterface $staleBefore,
        array $statuses = [PaymentStatus::PENDING, PaymentStatus::PROCESSING],
        int $limit = 100,
        ?DateTimeInterface $dueAt = null,
        ?DateTimeInterface $createdAfter = null,
    ): iterable {
        $dueAt ??= now();

        $query = Payment::query()
            ->whereIn('status', array_map(static fn (PaymentStatus $status): string => $status->value, $statuses))
            ->where(function ($query) use ($staleBefore, $dueAt): void {
                $query->where('next_reconcile_at', '<=', $dueAt)
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query->whereNull('next_reconcile_at')
                            ->where('updated_at', '<=', $staleBefore);
                    });
            });

        if ($createdAfter !== null) {
            $query->where('created_at', '>=', $createdAfter);
        }

        return $query
            ->orderByRaw('CASE WHEN next_reconcile_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('next_reconcile_at')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }

    public function save(Payment $payment): Payment
    {
        $payment->save();

        return $payment;
    }
}
