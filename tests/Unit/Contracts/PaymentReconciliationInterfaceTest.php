<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Contracts;

use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReconciliationInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentReconciliationInterfaceTest extends TestCase
{
    public function test_reconciliation_capability_exposes_a_normalized_result(): void
    {
        $gateway = new class implements PaymentReconciliationInterface {
            public function reconcilePayment(PaymentStatusRequest $request): PaymentStatusResult
            {
                return new PaymentStatusResult(
                    paymentReference: $request->paymentReference,
                    status: PaymentStatus::SUCCEEDED,
                );
            }
        };

        $result = $gateway->reconcilePayment(new PaymentStatusRequest('ORDER-1'));

        self::assertSame('ORDER-1', $result->paymentReference);
        self::assertSame(PaymentStatus::SUCCEEDED, $result->status);
    }
}
