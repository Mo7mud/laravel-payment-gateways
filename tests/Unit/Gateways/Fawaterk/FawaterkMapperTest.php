<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Fawaterk;

use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\CustomerData;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkMapper;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class FawaterkMapperTest extends TestCase
{
    public function test_hosted_response_maps_to_nullable_checkout_result(): void
    {
        $request = new CreatePaymentRequest(
            paymentReference: 'PAY-FAW-001',
            amount: Money::fromDecimal('150.00', Currency::egp()),
            customer: new CustomerData(name: 'Ahmed Ali', email: 'ahmed@example.com'),
        );

        $result = (new FawaterkMapper())->creationResult($request, [
            'status' => 'success',
            'data' => [
                'intent_key' => '550e8400-e29b-41d4-a716-446655440000',
                'url' => 'https://staging.fawaterk.com/ts/test',
                'expires_in' => 1800,
            ],
        ]);

        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertSame('https://staging.fawaterk.com/ts/test', $result->checkoutUrl);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $result->gatewayReference);
        self::assertNotNull($result->expiresAt);
    }

    public function test_direct_fawry_response_maps_without_checkout_url(): void
    {
        $request = new CreatePaymentRequest(
            paymentReference: 'PAY-FAW-002',
            amount: Money::fromDecimal('100.00', Currency::egp()),
            customer: new CustomerData(name: 'Ahmed Ali'),
        );

        $result = (new FawaterkMapper())->creationResult($request, [
            'status' => 'success',
            'data' => [
                'intent_key' => 'intent-fawry',
                'expires_in' => 3600,
                'payment_data' => [
                    'referenceNumber' => '981335305',
                    'expireDate' => '2026-07-06 15:53:41',
                    'expirationTime' => '2026-07-06 15:53:41',
                ],
            ],
        ]);

        self::assertNull($result->checkoutUrl);
        self::assertSame('981335305', $result->metadata['fawaterk_reference_number']);
        self::assertSame('reference', $result->metadata['fawaterk_payment_data_type']);
    }

    public function test_paid_webhook_uses_payment_reference_from_pay_load(): void
    {
        $payload = [
            'transaction_key' => 'intent-123',
            'transaction_id' => 12345,
            'payment_method' => 'Visa-Mastercard',
            'status' => 'paid',
            'pay_load' => json_encode(['payment_reference' => 'PAY-FAW-003'], JSON_THROW_ON_ERROR),
            'paidAmount' => '50.00',
            'paidCurrency' => 'EGP',
            'paidAt' => '2026-08-24 12:00:00',
        ];

        $result = (new FawaterkMapper())->webhookResult('paid', $payload);

        self::assertSame('PAY-FAW-003', $result->paymentReference);
        self::assertSame(PaymentStatus::SUCCEEDED, $result->status);
        self::assertSame('intent-123', $result->gatewayReference);
        self::assertSame('12345', $result->gatewayTransactionId);
        self::assertSame('50.00', $result->amount?->toDecimal());
    }

    public function test_refund_webhook_keeps_empty_local_reference_and_exposes_transaction_id_for_core_lookup(): void
    {
        $payload = [
            'transactionId' => 12345,
            'amount' => '25.00',
            'currency' => 'EGP',
            'status' => 1,
            'reason' => 'Customer changed their mind',
            'approvedAt' => '2026-08-24 12:05:00',
        ];

        $result = (new FawaterkMapper())->webhookResult('refund', $payload);

        self::assertSame('', $result->paymentReference);
        self::assertSame('12345', $result->gatewayTransactionId);
        self::assertSame('refund', $result->eventType);
        self::assertSame('25.00', $result->amount?->toDecimal());
    }
}
