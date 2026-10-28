<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;

/**
 * Informational only. observedStatus is what the browser redirect claims;
 * localStatus is the authoritative stored status when the payment is known.
 */
final readonly class PaymentReturnResult
{
    /**
     * @param  array<mixed>  $metadata
     * @param  array<mixed>  $rawQuery
     */
    public function __construct(
        public string $paymentReference,
        public PaymentStatus $observedStatus,
        public ?string $gatewayReference = null,
        public ?string $gatewayTransactionId = null,
        public ?string $gatewayStatus = null,
        public ?DateTimeImmutable $occurredAt = null,
        public array $metadata = [],
        public array $rawQuery = [],
        public ?PaymentStatus $localStatus = null,
        public bool $signatureVerified = false,
    ) {}

    public function withLocalPayment(string $paymentReference, PaymentStatus $localStatus): self
    {
        return new self(
            paymentReference: $paymentReference,
            observedStatus: $this->observedStatus,
            gatewayReference: $this->gatewayReference,
            gatewayTransactionId: $this->gatewayTransactionId,
            gatewayStatus: $this->gatewayStatus,
            occurredAt: $this->occurredAt,
            metadata: $this->metadata,
            rawQuery: $this->rawQuery,
            localStatus: $localStatus,
            signatureVerified: $this->signatureVerified,
        );
    }
}
