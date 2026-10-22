<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Models\Payment;

/**
 * Test double. Every read returns a copy, like a database would, so tests
 * cannot pass by sharing an object reference with the service.
 */
final class InMemoryPaymentRepository implements PaymentRepositoryInterface
{
    /** @var array<string, Payment> */
    private array $payments = [];

    public function findByReference(string $reference): ?Payment
    {
        return isset($this->payments[$reference]) ? clone $this->payments[$reference] : null;
    }

    public function findByReferenceForUpdate(string $reference): ?Payment
    {
        return $this->findByReference($reference);
    }

    public function findByGatewayReference(string $gateway, string $gatewayReference): ?Payment
    {
        return $this->first(static fn (Payment $p): bool => $p->gateway === $gateway && $p->gateway_reference === $gatewayReference);
    }

    public function findByGatewayTransactionId(string $gateway, string $gatewayTransactionId): ?Payment
    {
        return $this->first(static fn (Payment $p): bool => $p->gateway === $gateway && $p->gateway_transaction_id === $gatewayTransactionId);
    }

    public function findByGatewayOrderReference(string $gateway, string $gatewayOrderReference): ?Payment
    {
        return $this->first(static fn (Payment $p): bool => $p->gateway === $gateway && $p->gateway_order_reference === $gatewayOrderReference);
    }

    public function findReconciliationCandidates(
        DateTimeInterface $staleBefore,
        array $statuses = [PaymentStatus::PENDING, PaymentStatus::PROCESSING],
        int $limit = 100,
        ?DateTimeInterface $dueAt = null,
        ?DateTimeInterface $createdAfter = null,
    ): iterable {
        $dueAt ??= new DateTimeImmutable();
        $allowed = array_map(static fn (PaymentStatus $status): string => $status->value, $statuses);
        $candidates = [];

        foreach ($this->payments as $payment) {
            if (!in_array($payment->status->value, $allowed, true)) {
                continue;
            }

            if ($createdAfter !== null && $payment->created_at !== null && $payment->created_at->getTimestamp() < $createdAfter->getTimestamp()) {
                continue;
            }

            $next = $payment->next_reconcile_at;
            if ($next !== null) {
                if ($next->getTimestamp() > $dueAt->getTimestamp()) {
                    continue;
                }
            } else {
                $updatedAt = $payment->updated_at ?? $payment->created_at;
                if ($updatedAt !== null && $updatedAt->getTimestamp() > $staleBefore->getTimestamp()) {
                    continue;
                }
            }

            $candidates[] = clone $payment;
        }

        usort($candidates, static function (Payment $a, Payment $b): int {
            $aKey = $a->next_reconcile_at?->getTimestamp() ?? PHP_INT_MIN;
            $bKey = $b->next_reconcile_at?->getTimestamp() ?? PHP_INT_MIN;

            return $aKey <=> $bKey;
        });

        return array_slice($candidates, 0, $limit);
    }

    public function findUnfulfilledCandidates(
        DateTimeInterface $paidBefore,
        DateTimeInterface $dueAt,
        int $maxDispatches,
        int $limit = 100,
    ): iterable {
        $candidates = [];

        foreach ($this->payments as $payment) {
            $paidAt = $payment->paid_at;
            $next = $payment->next_fulfilment_dispatch_at;

            if ($payment->fulfilled_at !== null
                || !$payment->status->awaitsFulfilment()
                || $paidAt === null
                || $paidAt->getTimestamp() > $paidBefore->getTimestamp()
                || (int) ($payment->fulfilment_dispatches ?? 0) >= $maxDispatches
                || ($next !== null && $next->getTimestamp() > $dueAt->getTimestamp())) {
                continue;
            }

            $candidates[] = clone $payment;
        }

        usort($candidates, static fn (Payment $a, Payment $b): int => $a->paid_at?->getTimestamp() <=> $b->paid_at?->getTimestamp());

        return array_slice($candidates, 0, $limit);
    }

    public function save(Payment $payment): Payment
    {
        if ($payment->usesTimestamps()) {
            $payment->updateTimestamps();
        }
        $payment->exists = true;
        $payment->syncOriginal();
        $this->payments[(string) $payment->reference] = clone $payment;

        return $payment;
    }

    /** @param callable(Payment): bool $predicate */
    private function first(callable $predicate): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($predicate($payment)) {
                return clone $payment;
            }
        }

        return null;
    }
}
