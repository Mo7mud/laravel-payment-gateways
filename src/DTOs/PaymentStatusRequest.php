<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

/**
 * PaymentService always builds this request from the stored payment.
 * Identifiers supplied by callers of PaymentService are ignored.
 */
final readonly class PaymentStatusRequest
{
    public function __construct(
        public string $paymentReference,
        public ?string $gatewayReference = null,
        public ?string $gatewayTransactionId = null,
        public ?string $gatewayOrderReference = null,
        public ?Money $expectedAmount = null,
    ) {
    }

    public function merchantOrderReference(): string
    {
        return $this->gatewayOrderReference !== null && $this->gatewayOrderReference !== ''
            ? $this->gatewayOrderReference
            : $this->paymentReference;
    }
}
