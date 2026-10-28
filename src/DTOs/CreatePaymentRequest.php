<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final readonly class CreatePaymentRequest
{
    /**
     * @param  string|null  $gatewayOrderReference  Merchant order id sent to the gateway.
     * @param  array<mixed>  $metadata
     *                                  Set by PaymentService (equals the payment reference on the first attempt).
     */
    public function __construct(
        public string $paymentReference,
        public Money $amount,
        public ?CustomerData $customer = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = [],
        public ?string $gatewayOrderReference = null,
    ) {}

    public function merchantOrderReference(): string
    {
        return $this->gatewayOrderReference !== null && $this->gatewayOrderReference !== ''
            ? $this->gatewayOrderReference
            : $this->paymentReference;
    }

    public function withGatewayOrderReference(string $gatewayOrderReference): self
    {
        return new self(
            paymentReference: $this->paymentReference,
            amount: $this->amount,
            customer: $this->customer,
            returnUrl: $this->returnUrl,
            cancelUrl: $this->cancelUrl,
            metadata: $this->metadata,
            gatewayOrderReference: $gatewayOrderReference,
        );
    }
}
