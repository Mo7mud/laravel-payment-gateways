<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Repositories;

use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Models\Payment;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use PHPUnit\Framework\TestCase;

final class InMemoryPaymentRepositoryTest extends TestCase
{
    public function test_it_can_find_payment_by_gateway_reference_and_transaction_id(): void
    {
        $repository = new InMemoryPaymentRepository;
        $payment = new Payment;
        $payment->reference = 'PAY-REF-001';
        $payment->gateway = 'fawaterk';
        $payment->status = PaymentStatus::PENDING;
        $payment->gateway_reference = 'intent-001';
        $payment->gateway_transaction_id = '12345';
        $repository->save($payment);

        self::assertSame('PAY-REF-001', $repository->findByGatewayReference('fawaterk', 'intent-001')?->reference);
        self::assertSame('PAY-REF-001', $repository->findByGatewayTransactionId('fawaterk', '12345')?->reference);
    }
}
