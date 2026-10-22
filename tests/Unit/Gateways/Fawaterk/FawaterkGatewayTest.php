<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Fawaterk;

use Mo7mud\LaravelPaymentGateway\Contracts\PaymentCreatorInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\CustomerData;
use Mo7mud\LaravelPaymentGateway\Enums\GatewayName;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkClientInterface;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkMapper;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class FawaterkGatewayTest extends TestCase
{
    public function test_gateway_implements_common_creator_contract_and_maps_hosted_flow(): void
    {
        $client = new class implements FawaterkClientInterface {
            public array $payload = [];
            public function getPaymentMethods(): array { return []; }
            public function createTransaction(array $payload): array
            {
                $this->payload = $payload;
                return [
                    'status' => 'success',
                    'data' => [
                        'intent_key' => 'intent-001',
                        'url' => 'https://staging.fawaterk.com/ts/intent-001',
                        'expires_in' => 3600,
                    ],
                ];
            }
            public function getTransactionData(string $intentKey): array { return []; }
            public function getTransactionsData(string $startDate, string $endDate, int $perPage = 15, ?string $payloadFilter = null, int $page = 1): array { return []; }
            public function getRefundTypes(?string $lang = null): array { return []; }
            public function getRefundReasons(?string $lang = null): array { return []; }
            public function createRefund(array $payload): array { return []; }
            public function deleteRefund(int $refundId): array { return []; }
            public function listRefunds(): array { return []; }
            public function getRefundDetails(int $refundId): array { return []; }
        };

        $gateway = new FawaterkGateway($client, new FawaterkMapper(), new FawaterkSignatureVerifier('secret'));
        self::assertInstanceOf(PaymentCreatorInterface::class, $gateway);
        self::assertSame(GatewayName::FAWATERK, $gateway->name());
        self::assertTrue($gateway->capabilities()->supportsHostedCheckout());

        $result = $gateway->createPayment(new CreatePaymentRequest(
            paymentReference: 'PAY-FAW-004',
            amount: Money::fromDecimal('75.00', Currency::egp()),
            customer: new CustomerData(name: 'Ahmed Ali', email: 'ahmed@example.com', phone: '01000000000'),
            returnUrl: 'https://shop.test/payments/return',
            cancelUrl: 'https://shop.test/cart',
        ));

        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertSame('intent-001', $result->gatewayReference);
        self::assertSame('https://staging.fawaterk.com/ts/intent-001', $result->checkoutUrl);
        self::assertSame('75.00', (string) $client->payload['cartTotal']);
        self::assertSame(
            ['successUrl' => 'https://shop.test/payments/return', 'backUrl' => 'https://shop.test/cart'],
            $client->payload['redirectionUrls'],
        );
        self::assertSame('PAY-FAW-004', $client->payload['pay_load']['payment_reference']);
    }
}
