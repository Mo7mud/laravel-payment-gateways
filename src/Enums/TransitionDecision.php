<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Enums;

enum TransitionDecision: string
{
    case APPLY = 'apply';
    /** Capture confirmed after the payment was closed as failed/cancelled/expired. */
    case LATE_PAYMENT = 'late_payment';
    case NOOP = 'noop';
    /** The update is older than the current state (out-of-order delivery). */
    case STALE = 'stale';
    case INVALID = 'invalid';

    public function changesState(): bool
    {
        return $this === self::APPLY || $this === self::LATE_PAYMENT;
    }
}
