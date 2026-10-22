<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Enums;

enum WebhookEventKind: string
{
    /** Carries a payment state (paid, pending, authorized, cancelled...). */
    case PAYMENT = 'payment';
    /** A refund, void or reversal outcome; carries a RefundNotification. */
    case REFUND = 'refund';
    /** One payment attempt failed; the payment itself stays open. */
    case ATTEMPT_FAILED = 'attempt_failed';
    /** Recognised but intentionally not acted upon. */
    case IGNORED = 'ignored';
}
