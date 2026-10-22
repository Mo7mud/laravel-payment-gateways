<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Kashier;

use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierClientInterface;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class KashierRefundTest extends TestCase
{
    public function test_refund_uses_order_id_and_amount_without_a_transaction_id(): void
    {
        $client = new class implements KashierClientInterface {
            public ?string $orderId = null;
            public ?string $amount = null;
            public ?string $reason = null;

            public function createPaymentSession(array $payload): array { return []; }
            public function getPaymentSession(string $sessionId): array { return []; }
            public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array { return []; }

            public function refund(string $orderId, ?string $amount, ?string $reason): array
            {
                $this->orderId = $orderId;
                $this->amount = $amount;
                $this->reason = $reason;

                return [
                    'status' => 'SUCCESS',
                    'response' => [
                        'status' => 'SUCCESS',
                        'amount' => 3,
                        'currency' => 'EGP',
                        'transactionId' => 'TX-REFUND-1',
                        'totalRefundedAmount' => 3,
                        'totalCapturedAmount' => 10,
                    ],
                ];
            }
        };

        $gateway = new KashierGateway(
            $client,
            new KashierSignatureVerifier('secret'),
            new KashierStatusMapper(),
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

        $result = $gateway->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-REFUND-1',
            amount: Money::fromDecimal('3.00', Currency::egp()),
            reason: 'partial refund test',
            metadata: [
                'kashier_order_id' => 'ORDER-1',
                'kashier_transaction_id' => 'TX-PAY-1',
            ],
        ));

        self::assertSame('ORDER-1', $client->orderId);
        self::assertSame('3.00', $client->amount);
        self::assertSame('partial refund test', $client->reason);
        self::assertSame(RefundStatus::SUCCEEDED, $result->status);
        self::assertSame('TX-REFUND-1', $result->refundReference);
    }
}
