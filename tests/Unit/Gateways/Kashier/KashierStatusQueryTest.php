<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Kashier;

use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierClientInterface;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use PHPUnit\Framework\TestCase;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final class KashierStatusQueryTest extends TestCase
{
    public function test_create_payment_uses_the_gateway_configuration_for_session_payload(): void
    {
        $client = new class implements KashierClientInterface {
            public array $payload = [];

            public function createPaymentSession(array $payload): array
            {
                $this->payload = $payload;

                return [
                    '_id' => 'SESSION-CREATE-1',
                    'sessionUrl' => 'https://checkout.kashier.test/SESSION-CREATE-1',
                    'status' => 'CREATED',
                ];
            }

            public function getPaymentSession(string $sessionId): array { return []; }
            public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array { return []; }
            public function refund(string $orderId, ?string $amount, ?string $reason): array { return []; }
        };

        $gateway = new KashierGateway($client, new KashierSignatureVerifier('secret'), new KashierStatusMapper(), [
            'merchant_id' => 'MID-TEST',
            'secret_key' => 'secret',
            'payment_api_key' => 'payment-key',
            'environment' => 'test',
            'return_url' => 'https://example.com/return',
            'server_webhook' => '',
            'allowed_methods' => 'card,wallet',
            'payment_type' => 'credit',
            'default_method' => 'wallet',
            'display' => 'ar',
            'enable_3ds' => false,
            'failure_redirect' => true,
            'max_failure_attempts' => 5,
            'expire_minutes' => 45,
        ]);

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $result = $gateway->createPayment(new CreatePaymentRequest(
            paymentReference: 'PAY-CREATE-1',
            amount: Money::fromDecimal('10.00', Currency::egp()),
        ));

        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertSame('SESSION-CREATE-1', $result->gatewayReference);
        self::assertSame('wallet', $client->payload['defaultMethod']);
        self::assertSame('ar', $client->payload['display']);
        self::assertFalse($client->payload['enable3DS']);
        self::assertTrue($client->payload['failureRedirect']);
        self::assertSame(5, $client->payload['maxFailureAttempts']);
        self::assertSame('credit', $client->payload['paymentType']);
        self::assertSame('PAY-CREATE-1', $client->payload['order']);
        self::assertGreaterThanOrEqual(44 * 60, $result->expiresAt->getTimestamp() - $before->getTimestamp());
        self::assertLessThanOrEqual(46 * 60, $result->expiresAt->getTimestamp() - $before->getTimestamp());
    }

    public function test_captured_order_is_mapped_to_succeeded_and_transaction_is_extracted(): void
    {
        $client = new class implements KashierClientInterface {
            public function createPaymentSession(array $payload): array { return []; }
            public function getPaymentSession(string $sessionId): array
            {
                \PHPUnit\Framework\Assert::assertSame('6a7f34b40a49ed120d0bd3d9', $sessionId);
                return [
                    'message' => 'success',
                    'data' => [
                        'sessionId' => '6a7f34b40a49ed120d0bd3d9',
                        'status' => 'PAID',
                        'createdAt' => '2026-08-14T15:31:00.982Z',
                        'updatedAt' => '2026-08-14T15:32:50.733Z',
                        'merchantOrderId' => 'SANDBOX-60Z56EZMVVRV4V7H',
                        'amount' => 1,
                        'currency' => 'EGP',
                        'orderId' => '7642d44c-335d-4ae5-89f6-70486100f6a7',
                        'capturedAmount' => 1,
                        'refundedAmount' => 0,
                        'targetTransactionId' => 'TX-4885276908',
                        'history' => [[
                            'orderId' => '7642d44c-335d-4ae5-89f6-70486100f6a7',
                            'status' => 'SUCCESS',
                            'date' => '2026-08-14T15:32:38.803Z',
                            'transactionId' => 'TX-4885276908',
                            'operation' => 'pay',
                            'amount' => 1,
                            'transactionResponseCode' => '00',
                        ]],
                    ],
                ];
            }
            public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array
            {
                \PHPUnit\Framework\Assert::assertSame('SANDBOX-60Z56EZMVVRV4V7H', $merchantOrderId);
                return [
                    'status' => 'SUCCESS',
                    'data' => [[
                        'sessionId' => '6a7f34b40a49ed120d0bd3d9',
                        'orderId' => '7642d44c-335d-4ae5-89f6-70486100f6a7',
                        'merchantOrderId' => 'SANDBOX-60Z56EZMVVRV4V7H',
                        'status' => 'CAPTURED',
                        'totalCapturedAmount' => 1,
                        'totalRefundedAmount' => 0,
                        'totalAuthorizedAmount' => 1,
                        'order' => ['amount' => 1, 'currency' => 'EGP'],
                        'transactions' => [[
                            'operation' => 'pay',
                            'transactionId' => 'TX-4885276908',
                            'status' => 'SUCCESS',
                            'amount' => 1,
                            'currency' => 'EGP',
                            'transactionResponseCode' => '00',
                            'reconcilation' => 'Pending',
                            'merchantWebhookReconciliation' => 'NA',
                            'isCurrentlyReconciled' => true,
                            'responseDate' => '2026-08-14T15:32:38.000Z',
                        ]],
                    ]],
                ];
            }
            public function refund(string $orderId, ?string $amount, ?string $reason): array { return []; }
        };

        $gateway = new KashierGateway($client, new KashierSignatureVerifier('secret'), new KashierStatusMapper(), [
            'merchant_id' => 'MID-TEST', 'secret_key' => 'secret', 'payment_api_key' => 'payment-key', 'environment' => 'test',
            'return_url' => 'https://example.com/return', 'server_webhook' => '', 'allowed_methods' => 'card,wallet',
        ]);

        $result = $gateway->reconcilePayment(new PaymentStatusRequest('SANDBOX-60Z56EZMVVRV4V7H', '6a7f34b40a49ed120d0bd3d9'));

        self::assertSame(PaymentStatus::SUCCEEDED, $result->status);
        self::assertSame('TX-4885276908', $result->gatewayTransactionId);
        self::assertSame('7642d44c-335d-4ae5-89f6-70486100f6a7', $result->metadata['kashier_order_id']);
        self::assertSame(100, $result->amount?->minorAmount);
        self::assertSame(100, $result->paidAmount?->minorAmount);
        self::assertSame(0, $result->refundedAmount?->minorAmount);
    }


    public function test_direct_session_status_uses_session_endpoint_and_maps_paid(): void
    {
        $client = new class implements KashierClientInterface {
            public function createPaymentSession(array $payload): array { return []; }
            public function getPaymentSession(string $sessionId): array {
                return [
                    'data' => [
                        'sessionId' => $sessionId,
                        'status' => 'PAID',
                        'amount' => 1,
                        'currency' => 'EGP',
                        'capturedAmount' => 1,
                        'refundedAmount' => 0,
                        'targetTransactionId' => 'TX-1',
                        'updatedAt' => '2026-08-14T15:32:50.733Z',
                        'history' => [
                            ['status' => 'SUCCESS', 'date' => '2026-08-14T15:32:38.803Z', 'transactionId' => 'TX-1', 'operation' => 'pay'],
                        ],
                    ],
                ];
            }
            public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array {
                throw new \RuntimeException('Order reconciliation endpoint must not be used for direct status query.');
            }
            public function refund(string $orderId, ?string $amount, ?string $reason): array { return []; }
        };

        $gateway = new KashierGateway($client, new KashierSignatureVerifier('secret'), new KashierStatusMapper(), [
            'merchant_id' => 'MID-TEST', 'secret_key' => 'secret', 'payment_api_key' => 'payment-key', 'environment' => 'test',
            'return_url' => 'https://example.com/return', 'server_webhook' => '', 'allowed_methods' => 'card,wallet',
        ]);

        $result = $gateway->getPaymentStatus(new PaymentStatusRequest('ORDER-SESSION', 'SESSION-1'));

        self::assertSame(PaymentStatus::SUCCEEDED, $result->status);
        self::assertSame('SESSION-1', $result->gatewayReference);
        self::assertSame('TX-1', $result->gatewayTransactionId);
        self::assertSame(100, $result->paidAmount?->minorAmount);
    }

    public function test_reconciliation_uses_order_search_endpoint(): void
    {
        $client = new class implements KashierClientInterface {
            public function createPaymentSession(array $payload): array { return []; }
            public function getPaymentSession(string $sessionId): array {
                throw new \RuntimeException('Session endpoint must not be used for reconciliation.');
            }
            public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array {
                \PHPUnit\Framework\Assert::assertSame('ORDER-RECON', $merchantOrderId);
                return [
                    'status' => 'SUCCESS',
                    'data' => [[
                        'sessionId' => 'SESSION-RECON',
                        'orderId' => 'ORDER-ID-RECON',
                        'merchantOrderId' => 'ORDER-RECON',
                        'status' => 'CAPTURED',
                        'totalCapturedAmount' => 1,
                        'totalRefundedAmount' => 0,
                        'order' => ['amount' => 1, 'currency' => 'EGP'],
                        'transactions' => [[
                            'operation' => 'pay',
                            'transactionId' => 'TX-RECON',
                            'status' => 'SUCCESS',
                            'amount' => 1,
                            'currency' => 'EGP',
                            'transactionResponseCode' => '00',
                            'reconcilation' => 'Pending',
                            'merchantWebhookReconciliation' => 'NA',
                            'isCurrentlyReconciled' => true,
                        ]],
                    ]],
                ];
            }
            public function refund(string $orderId, ?string $amount, ?string $reason): array { return []; }
        };

        $gateway = new KashierGateway($client, new KashierSignatureVerifier('secret'), new KashierStatusMapper(), [
            'merchant_id' => 'MID-TEST', 'secret_key' => 'secret', 'payment_api_key' => 'payment-key', 'environment' => 'test',
            'return_url' => 'https://example.com/return', 'server_webhook' => '', 'allowed_methods' => 'card,wallet',
        ]);

        $result = $gateway->reconcilePayment(new PaymentStatusRequest('ORDER-RECON'));

        self::assertSame(PaymentStatus::SUCCEEDED, $result->status);
        self::assertSame('SESSION-RECON', $result->gatewayReference);
        self::assertSame('TX-RECON', $result->gatewayTransactionId);
        self::assertSame('Pending', $result->metadata['kashier_reconciliation_status']);
    }


    public function test_reconciliation_does_not_fall_back_to_an_unrelated_order(): void
    {
        $client = new class implements KashierClientInterface {
            public function createPaymentSession(array $payload): array { return []; }
            public function getPaymentSession(string $sessionId): array { return []; }
            public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array
            {
                return [
                    'status' => 'SUCCESS',
                    'data' => [[
                        'sessionId' => 'UNRELATED-SESSION',
                        'orderId' => 'UNRELATED-ORDER',
                        'merchantOrderId' => 'OTHER-ORDER',
                        'status' => 'CAPTURED',
                        'totalCapturedAmount' => 1,
                        'totalRefundedAmount' => 0,
                        'order' => ['amount' => 1, 'currency' => 'EGP'],
                        'transactions' => [[
                            'operation' => 'pay',
                            'transactionId' => 'OTHER-TX',
                            'status' => 'SUCCESS',
                            'amount' => 1,
                            'currency' => 'EGP',
                        ]],
                    ]],
                ];
            }
            public function refund(string $orderId, ?string $amount, ?string $reason): array { return []; }
        };

        $gateway = new KashierGateway($client, new KashierSignatureVerifier('secret'), new KashierStatusMapper(), [
            'merchant_id' => 'MID-TEST', 'secret_key' => 'secret', 'payment_api_key' => 'payment-key', 'environment' => 'test',
            'return_url' => 'https://example.com/return', 'server_webhook' => '', 'allowed_methods' => 'card,wallet',
        ]);

        $this->expectException(\Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierConfigurationException::class);
        $gateway->reconcilePayment(new PaymentStatusRequest('TARGET-ORDER', 'TARGET-SESSION', 'TARGET-TX'));
    }

    public function test_fully_refunded_order_is_mapped_to_refunded(): void
    {
        $client = new class implements KashierClientInterface {
            public function createPaymentSession(array $payload): array { return []; }
            public function getPaymentSession(string $sessionId): array
            {
                \PHPUnit\Framework\Assert::assertSame('6a7f34b40a49ed120d0bd3d9', $sessionId);
                return [
                    'message' => 'success',
                    'data' => [
                        'sessionId' => '6a7f34b40a49ed120d0bd3d9',
                        'status' => 'PAID',
                        'createdAt' => '2026-08-14T15:31:00.982Z',
                        'updatedAt' => '2026-08-14T15:32:50.733Z',
                        'merchantOrderId' => 'SANDBOX-60Z56EZMVVRV4V7H',
                        'amount' => 1,
                        'currency' => 'EGP',
                        'orderId' => '7642d44c-335d-4ae5-89f6-70486100f6a7',
                        'capturedAmount' => 1,
                        'refundedAmount' => 0,
                        'targetTransactionId' => 'TX-4885276908',
                        'history' => [[
                            'orderId' => '7642d44c-335d-4ae5-89f6-70486100f6a7',
                            'status' => 'SUCCESS',
                            'date' => '2026-08-14T15:32:38.803Z',
                            'transactionId' => 'TX-4885276908',
                            'operation' => 'pay',
                            'amount' => 1,
                            'transactionResponseCode' => '00',
                        ]],
                    ],
                ];
            }
            public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array
            {
                return ['status' => 'SUCCESS', 'data' => [[
                    'merchantOrderId' => 'ORDER-REF',
                    'orderId' => 'ORDER-ID-REF',
                    'status' => 'CAPTURED', 'totalCapturedAmount' => 10, 'totalRefundedAmount' => 10,
                    'order' => ['amount' => 10, 'currency' => 'EGP'], 'transactions' => [],
                ]]];
            }
            public function refund(string $orderId, ?string $amount, ?string $reason): array { return []; }
        };

        $gateway = new KashierGateway($client, new KashierSignatureVerifier('secret'), new KashierStatusMapper(), [
            'merchant_id' => 'MID-TEST', 'secret_key' => 'secret', 'payment_api_key' => 'payment-key', 'environment' => 'test',
            'return_url' => 'https://example.com/return', 'server_webhook' => '', 'allowed_methods' => 'card,wallet',
        ]);

        $result = $gateway->reconcilePayment(new PaymentStatusRequest('ORDER-REF'));
        self::assertSame(PaymentStatus::REFUNDED, $result->status);
    }
}
