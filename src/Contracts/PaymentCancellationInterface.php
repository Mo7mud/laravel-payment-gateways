<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;

/**
 * Gateways that can close an unpaid checkout. Never moves money. Returns the
 * gateway status after the call: a payment captured just before stays paid.
 */
interface PaymentCancellationInterface
{
    public function cancelPayment(PaymentStatusRequest $request): PaymentStatusResult;
}
