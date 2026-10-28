<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Models\PaymentRefund;

final class InMemoryRefundRepository implements RefundRepositoryInterface
{
    /** @var array<string, PaymentRefund> keyed by uuid, in insertion order */
    private array $refunds = [];

    /** @var array<string, DateTimeImmutable> */
    private array $createdAt = [];

    public function save(PaymentRefund $refund): PaymentRefund
    {
        $uuid = (string) $refund->uuid;
        if (! isset($this->createdAt[$uuid])) {
            $this->createdAt[$uuid] = new DateTimeImmutable;
        }
        $refund->exists = true;
        $this->refunds[$uuid] = clone $refund;

        return $refund;
    }

    public function findByUuid(string $uuid): ?PaymentRefund
    {
        return isset($this->refunds[$uuid]) ? clone $this->refunds[$uuid] : null;
    }

    public function findByIdempotencyKey(string $paymentReference, string $idempotencyKey): ?PaymentRefund
    {
        return $this->first(static fn (PaymentRefund $r): bool => $r->payment_reference === $paymentReference && $r->idempotency_key === $idempotencyKey);
    }

    public function findByGatewayReference(string $gateway, string $gatewayRefundReference): ?PaymentRefund
    {
        return $this->first(static fn (PaymentRefund $r): bool => $r->gateway === $gateway && $r->gateway_refund_reference === $gatewayRefundReference);
    }

    public function findOldestUnmatchedPending(string $paymentReference, int $amountMinor): ?PaymentRefund
    {
        return $this->first(static fn (PaymentRefund $r): bool => $r->payment_reference === $paymentReference
            && $r->status === RefundStatus::PENDING
            && (int) $r->amount_minor === $amountMinor
            && ($r->gateway_refund_reference === null || $r->gateway_refund_reference === ''));
    }

    public function pendingFor(string $paymentReference): array
    {
        $result = [];
        foreach ($this->refunds as $refund) {
            if ($refund->payment_reference === $paymentReference && $refund->status === RefundStatus::PENDING) {
                $result[] = clone $refund;
            }
        }

        return $result;
    }

    public function sumAmount(string $paymentReference, RefundStatus $status): int
    {
        $sum = 0;
        foreach ($this->refunds as $refund) {
            if ($refund->payment_reference === $paymentReference && $refund->status === $status) {
                $sum += (int) $refund->amount_minor;
            }
        }

        return $sum;
    }

    public function paymentsWithPendingRefunds(DateTimeInterface $before, int $limit = 100): array
    {
        $references = [];
        foreach ($this->refunds as $uuid => $refund) {
            if ($refund->status === RefundStatus::PENDING && $this->createdAt[$uuid]->getTimestamp() <= $before->getTimestamp()) {
                $references[(string) $refund->payment_reference] = true;
            }
        }

        return array_slice(array_keys($references), 0, $limit);
    }

    /** @param callable(PaymentRefund): bool $predicate */
    private function first(callable $predicate): ?PaymentRefund
    {
        foreach ($this->refunds as $refund) {
            if ($predicate($refund)) {
                return clone $refund;
            }
        }

        return null;
    }
}
