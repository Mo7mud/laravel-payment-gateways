<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Events;

final class PaymentStatusChanged extends PaymentEvent
{
    public function __construct(
        string $paymentReference,
        string $gateway,
        public readonly string $from,
        public readonly string $to,
        public readonly string $source,
    ) {
        parent::__construct($paymentReference, $gateway);
    }
}
