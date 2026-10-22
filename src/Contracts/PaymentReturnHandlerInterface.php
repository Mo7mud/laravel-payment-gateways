<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnResult;

interface PaymentReturnHandlerInterface
{
    public function handleReturn(PaymentReturnRequest $request): PaymentReturnResult;
}
