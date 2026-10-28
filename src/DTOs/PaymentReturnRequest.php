<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

final readonly class PaymentReturnRequest
{
    /**
     * @param  array<mixed>  $metadata
     * @param  array<mixed>  $query
     */
    public function __construct(
        public array $query,
        public array $metadata = [],
    ) {}
}
