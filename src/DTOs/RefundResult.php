<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final readonly class RefundResult
{
    /**
     * @param  Money|null  $totalRefunded  Cumulative refunded amount reported by the gateway, when available.
     * @param  array<mixed>  $metadata
     */
    public function __construct(
        public string $paymentReference,
        public RefundStatus $status,
        public ?Money $amount = null,
        public ?string $refundReference = null,
        public ?string $gatewayStatus = null,
        public ?DateTimeImmutable $occurredAt = null,
        public array $metadata = [],
        public ?Money $totalRefunded = null,
    ) {}
}
