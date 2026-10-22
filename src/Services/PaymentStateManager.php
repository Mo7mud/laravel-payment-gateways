<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Services;

use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\TransitionDecision;
use Mo7mud\LaravelPaymentGateway\Exceptions\InvalidPaymentStateTransitionException;

final class PaymentStateManager
{
    public function canTransition(PaymentStatus $from, PaymentStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, $this->allowedTransitions()[$from->value] ?? [], true);
    }

    public function transition(PaymentStatus $from, PaymentStatus $to): PaymentStatus
    {
        if (!$this->canTransition($from, $to)) {
            throw new InvalidPaymentStateTransitionException($from, $to);
        }

        return $to;
    }

    /**
     * Decides how an update reported by a gateway should be applied.
     * Out-of-order or older updates are STALE and must be ignored, never thrown:
     * gateways retry anything that is not acknowledged.
     */
    public function decide(PaymentStatus $from, PaymentStatus $to): TransitionDecision
    {
        if ($from === $to) {
            return TransitionDecision::NOOP;
        }

        if ($to === PaymentStatus::SUCCEEDED && $from->isClosedUnpaid()) {
            return TransitionDecision::LATE_PAYMENT;
        }

        if ($this->canTransition($from, $to)) {
            return TransitionDecision::APPLY;
        }

        if ($to->rank() < $from->rank()) {
            return TransitionDecision::STALE;
        }

        return TransitionDecision::INVALID;
    }

    /** @return array<string, list<PaymentStatus>> */
    private function allowedTransitions(): array
    {
        return [
            PaymentStatus::INITIATING->value => [
                PaymentStatus::PENDING,
                PaymentStatus::PROCESSING,
                PaymentStatus::SUCCEEDED,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::EXPIRED,
                PaymentStatus::CREATION_FAILED,
            ],
            PaymentStatus::CREATION_FAILED->value => [
                PaymentStatus::INITIATING,
                // cancelPayment(): the order was abandoned before a checkout existed.
                PaymentStatus::CANCELLED,
            ],
            PaymentStatus::PENDING->value => [
                PaymentStatus::PROCESSING,
                PaymentStatus::SUCCEEDED,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::EXPIRED,
            ],
            PaymentStatus::PROCESSING->value => [
                PaymentStatus::SUCCEEDED,
                PaymentStatus::FAILED,
                PaymentStatus::CANCELLED,
                PaymentStatus::EXPIRED,
            ],
            // A capture confirmed after closing is recorded as a late payment
            // (see decide()); nothing else re-opens a closed payment.
            PaymentStatus::FAILED->value => [PaymentStatus::SUCCEEDED],
            PaymentStatus::CANCELLED->value => [PaymentStatus::SUCCEEDED],
            PaymentStatus::EXPIRED->value => [PaymentStatus::SUCCEEDED],
            PaymentStatus::SUCCEEDED->value => [
                PaymentStatus::REFUNDING,
                PaymentStatus::PARTIALLY_REFUNDED,
                PaymentStatus::REFUNDED,
            ],
            PaymentStatus::PARTIALLY_REFUNDED->value => [
                PaymentStatus::REFUNDING,
                PaymentStatus::REFUNDED,
                PaymentStatus::PARTIALLY_REFUNDED,
            ],
            PaymentStatus::REFUNDING->value => [
                PaymentStatus::PARTIALLY_REFUNDED,
                PaymentStatus::REFUNDED,
                PaymentStatus::SUCCEEDED,
            ],
            PaymentStatus::REFUNDED->value => [],
        ];
    }
}
