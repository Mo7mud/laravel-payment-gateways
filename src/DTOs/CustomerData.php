<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

final readonly class CustomerData
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $reference = null,
    ) {
    }
}
