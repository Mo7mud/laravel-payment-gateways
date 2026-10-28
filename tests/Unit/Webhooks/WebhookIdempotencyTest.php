<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Webhooks;

use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\CustomerData;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class WebhookIdempotencyTest extends TestCase
{
    public function test_duplicate_webhook_is_ignored(): void
    {
        $gateway = new MockGateway;
        $service = new PaymentService(
            new GatewayManager([$gateway]),
            $payments = new InMemoryPaymentRepository,
            new PaymentStateManager,
            $events = new InMemoryWebhookEventRepository,
            new InMemoryTransactionRunner,
        );

        $service->createPayment('mock', new CreatePaymentRequest(
            paymentReference: 'PAY-1',
            amount: Money::fromMinor(10000, Currency::egp()),
            customer: new CustomerData('1', 'Test', 'test@example.com', '01000000000'),
        ));

        $payload = [
            'event_id' => 'evt-1',
            'event_type' => 'payment.updated',
            'payment_reference' => 'PAY-1',
            'status' => 'succeeded',
            'gateway_status' => 'SUCCESS',
            'gateway_reference' => 'mock-PAY-1',
            'gateway_transaction_id' => 'mock-tx-PAY-1',
        ];

        $first = $service->handleWebhook(new WebhookRequest($payload), 'mock');
        $second = $service->handleWebhook(new WebhookRequest($payload), 'mock');
        $changedDuplicatePayload = $payload;
        $changedDuplicatePayload['status'] = 'failed';
        $third = $service->handleWebhook(new WebhookRequest($changedDuplicatePayload), 'mock');

        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertTrue($third->duplicate);
        self::assertSame('succeeded', $payments->findByReference('PAY-1')?->status->value);
        self::assertNotNull($events->find('mock', 'evt-1'));
    }
}
