<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

final readonly class PaymentReturnRequest
{
    public function __construct(
        public array $query,
        public array $metadata = [],
    ) {}
}
