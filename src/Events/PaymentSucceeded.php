<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Events;

/** Money was collected. Fulfil the order here, not in the return/redirect handler. */
final class PaymentSucceeded extends PaymentEvent
{
    public function __construct(
        string $paymentReference,
        string $gateway,
        public readonly bool $latePayment = false,
    ) {
        parent::__construct($paymentReference, $gateway);
    }
}
