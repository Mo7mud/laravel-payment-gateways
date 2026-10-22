<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;

/**
 * Contract (عقد) for gateway-specific reconciliation (المطابقة) support.
 */
interface PaymentReconciliationInterface
{
    public function reconcilePayment(PaymentStatusRequest $request): PaymentStatusResult;
}
