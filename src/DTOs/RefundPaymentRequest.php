<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final readonly class RefundPaymentRequest
{
    /**
     * @param  array<mixed>  $metadata
     */
    public function __construct(
        public string $paymentReference,
        public ?Money $amount = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {}
}
