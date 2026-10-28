<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Kashier;

use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\CustomerData;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierClientInterface;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class KashierCustomerMappingTest extends TestCase
{
    public function test_customer_reference_is_sent_to_kashier(): void
    {
        $client = new class implements KashierClientInterface
        {
            public array $payload = [];

            public function createPaymentSession(array $payload): array
            {
                $this->payload = $payload;

                return [
                    '_id' => 'session-1',
                    'status' => 'CREATED',
                    'expireAt' => '2026-08-14T20:00:00.000Z',
                    'sessionUrl' => 'https://payments.kashier.io/session/session-1?mode=test',
                    'paymentParams' => [],
                ];
            }

            public function getPaymentSession(string $sessionId): array
            {
                return [];
            }

            public function getOrderStatus(
                string $merchantOrderId,
                ?string $status = null,
                ?string $startDate = null,
                ?string $endDate = null
            ): array {
                throw new \LogicException('getOrderStatus() should not be called in this test.');
            }

            public function refund(string $orderId, ?string $amount, ?string $reason): array
            {
                return [];
            }
        };

        $gateway = new KashierGateway(
            $client,
            new KashierSignatureVerifier('secret'),
            new KashierStatusMapper,
            [
                'merchant_id' => 'MID-TEST',
                'secret_key' => 'secret',
                'payment_api_key' => 'payment-key',
                'environment' => 'test',
                'return_url' => 'https://example.com/return',
                'server_webhook' => '',
                'allowed_methods' => 'card,wallet',
                'payment_type' => 'credit',
                'default_method' => 'card',
                'enable_3ds' => true,
                'failure_redirect' => false,
                'max_failure_attempts' => 3,
                'expire_minutes' => 30,
                'display' => 'en',
            ],
        );

        $gateway->createPayment(new CreatePaymentRequest(
            paymentReference: 'PAY-1',
            amount: Money::fromDecimal('1.00', Currency::fromCode('EGP', 2)),
            customer: new CustomerData(
                id: 'internal-id',
                name: 'Test',
                email: 'test@example.com',
                reference: 'customer-ref-123',
            ),
        ));

        self::assertSame('customer-ref-123', $client->payload['customer']['reference']);
    }

    public function test_customer_reference_falls_back_to_id_for_backward_compatibility(): void
    {
        $client = new class implements KashierClientInterface
        {
            public array $payload = [];

            public function createPaymentSession(array $payload): array
            {
                $this->payload = $payload;

                return [
                    '_id' => 'session-2',
                    'status' => 'CREATED',
                    'expireAt' => '2026-08-14T20:00:00.000Z',
                    'sessionUrl' => 'https://payments.kashier.io/session/session-2?mode=test',
                    'paymentParams' => [],
                ];
            }

            public function getPaymentSession(string $sessionId): array
            {
                return [];
            }

            public function getOrderStatus(
                string $merchantOrderId,
                ?string $status = null,
                ?string $startDate = null,
                ?string $endDate = null
            ): array {
                throw new \LogicException('getOrderStatus() should not be called in this test.');
            }

            public function refund(string $orderId, ?string $amount, ?string $reason): array
            {
                return [];
            }
        };

        $gateway = new KashierGateway(
            $client,
            new KashierSignatureVerifier('secret'),
            new KashierStatusMapper,
            [
                'merchant_id' => 'MID-TEST',
                'secret_key' => 'secret',
                'payment_api_key' => 'payment-key',
                'environment' => 'test',
                'return_url' => 'https://example.com/return',
                'server_webhook' => '',
                'allowed_methods' => 'card,wallet',
                'payment_type' => 'credit',
                'default_method' => 'card',
                'enable_3ds' => true,
                'failure_redirect' => false,
                'max_failure_attempts' => 3,
                'expire_minutes' => 30,
                'display' => 'en',
            ],
        );

        $gateway->createPayment(new CreatePaymentRequest(
            paymentReference: 'PAY-2',
            amount: Money::fromDecimal('1.00', Currency::fromCode('EGP', 2)),
            customer: new CustomerData(id: 'legacy-id'),
        ));

        self::assertSame('legacy-id', $client->payload['customer']['reference']);
    }
}
