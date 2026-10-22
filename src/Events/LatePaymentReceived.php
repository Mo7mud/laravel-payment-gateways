<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Events;

/**
 * A capture was confirmed after the payment had been closed as failed,
 * cancelled or expired. Decide whether to fulfil or refund.
 */
final class LatePaymentReceived extends PaymentEvent
{
    public function __construct(
        string $paymentReference,
        string $gateway,
        public readonly string $previousStatus,
    ) {
        parent::__construct($paymentReference, $gateway);
    }
}
