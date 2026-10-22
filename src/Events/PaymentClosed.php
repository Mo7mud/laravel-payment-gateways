<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Events;

/** The payment ended without money being collected (failed, cancelled or expired). */
final class PaymentClosed extends PaymentEvent
{
    public function __construct(
        string $paymentReference,
        string $gateway,
        public readonly string $status,
    ) {
        parent::__construct($paymentReference, $gateway);
    }
}
