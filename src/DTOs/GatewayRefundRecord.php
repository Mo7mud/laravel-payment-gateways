<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

/** One refund as reported by the gateway API. */
final readonly class GatewayRefundRecord
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public RefundStatus $status,
        public ?string $gatewayStatus = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
