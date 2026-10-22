<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Events;

/** One attempt failed; the payment is still open and the customer may retry. */
final class PaymentAttemptFailed extends PaymentEvent
{
    public function __construct(
        string $paymentReference,
        string $gateway,
        public readonly ?string $gatewayStatus,
        public readonly ?string $reason,
    ) {
        parent::__construct($paymentReference, $gateway);
    }
}
