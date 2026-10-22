<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;

interface PaymentStatusProviderInterface
{
    public function getPaymentStatus(PaymentStatusRequest $request): PaymentStatusResult;
}
