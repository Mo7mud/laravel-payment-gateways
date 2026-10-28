<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Testing;

use Mo7mud\LaravelPaymentGateway\Contracts\PaymentCreatorInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentGatewayInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentRefundInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentStatusProviderInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\WebhookHandlerInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\Enums\GatewayName;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class MockGatewayTest extends TestCase
{
    private MockGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new MockGateway;
    }

    public function test_mock_gateway_implements_the_generic_contracts(): void
    {
        self::assertInstanceOf(PaymentGatewayInterface::class, $this->gateway);
        self::assertInstanceOf(PaymentCreatorInterface::class, $this->gateway);
        self::assertInstanceOf(PaymentStatusProviderInterface::class, $this->gateway);
        self::assertInstanceOf(PaymentRefundInterface::class, $this->gateway);
        self::assertInstanceOf(WebhookHandlerInterface::class, $this->gateway);
        self::assertSame(GatewayName::MOCK, $this->gateway->name());
    }

    public function test_create_and_query_flow_is_gateway_agnostic(): void
    {
        $request = new CreatePaymentRequest(
            paymentReference: 'PAY-001',
            amount: Money::fromDecimal('100.50', Currency::egp()),
        );

        $created = $this->gateway->createPayment($request);

        self::assertSame(PaymentStatus::PENDING, $created->status);
        self::assertSame('PAY-001', $created->paymentReference);

        $status = $this->gateway->getPaymentStatus(new PaymentStatusRequest('PAY-001'));
        self::assertSame(PaymentStatus::PENDING, $status->status);
    }
}
