<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final readonly class PaymentCreationResult
{
    /**
     * @param  array<mixed>  $metadata
     */
    public function __construct(
        public string $paymentReference,
        public PaymentStatus $status,
        public ?string $checkoutUrl = null,
        public ?string $gatewayReference = null,
        public ?string $gatewayTransactionId = null,
        public ?string $gatewayStatus = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?Money $amount = null,
        public array $metadata = [],
    ) {}
}
