<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentCreationResult;

interface PaymentCreatorInterface
{
    public function createPayment(CreatePaymentRequest $request): PaymentCreationResult;
}
