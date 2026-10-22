<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Fawaterk;

use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\CustomerData;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventKind;
use Mo7mud\LaravelPaymentGateway\Exceptions\InvalidRefundException;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkMapper;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Tests\Support\FakeFawaterkClient;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class FawaterkHardeningTest extends TestCase
{
    private const VENDOR_KEY = 'vendor-key';

    public function test_a_failed_notification_replayed_as_paid_does_not_mark_the_payment_paid(): void
    {
        $client = new FakeFawaterkClient();
        [$service, $payments] = $this->service($client);

        $service->createPayment('fawaterk', new CreatePaymentRequest(
            'PAY-F1',
            Money::fromDecimal('100.00', Currency::egp()),
            new CustomerData(name: 'Ahmed Ali'),
        ));

        // The paid and failed notifications sign the same string, so a captured
        // "failed" signature validates a forged "paid" body.
        $signature = hash_hmac('sha256', 'TransactionId=555&TransactionKey=intent-1&PaymentMethod=Card', self::VENDOR_KEY);
        $forged = [
            'transaction_id' => 555,
            'transaction_key' => 'intent-1',
            'payment_method' => 'Card',
            'transactionHashKey' => $signature,
            'status' => 'paid',
            'paidAmount' => '100.00',
            'paidCurrency' => 'EGP',
            'pay_load' => json_encode(['payment_reference' => 'PAY-F1'], JSON_THROW_ON_ERROR),
        ];

        $client->transaction = $this->transaction(paid: false);
        $result = $service->handleWebhook(new WebhookRequest($forged, [], null, 'paid_json'), 'fawaterk');

        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertSame(PaymentStatus::PENDING, $payments->findByReference('PAY-F1')?->status);

        // The genuine notification shares the dedupe key and must still be processed.
        $client->transaction = $this->transaction(paid: true);
        $genuine = $service->handleWebhook(new WebhookRequest($forged, [], null, 'paid_json'), 'fawaterk');

        self::assertFalse($genuine->duplicate);
        self::assertSame(PaymentStatus::SUCCEEDED, $payments->findByReference('PAY-F1')?->status);
    }

    public function test_failed_and_cancel_notifications_do_not_close_the_invoice(): void
    {
        $mapper = new FawaterkMapper();

        self::assertSame(WebhookEventKind::ATTEMPT_FAILED, $mapper->webhookResult('failed', [
            'transaction_id' => 1, 'transaction_key' => 'intent-1', 'payment_method' => 'Card', 'errorMessage' => 'Declined',
        ])->kind);
        self::assertSame(WebhookEventKind::ATTEMPT_FAILED, $mapper->webhookResult('cancelled', [
            'referenceId' => '981335305', 'paymentMethod' => 'Fawry', 'transactionKey' => 'intent-1',
        ])->kind);
        self::assertFalse($mapper->webhookResult('paid', ['transaction_key' => 'intent-1', 'status' => 'paid'])->referenceTrusted);
    }

    public function test_egp_converted_totals_are_not_compared_to_foreign_currency_payments(): void
    {
        $mapper = new FawaterkMapper();
        $response = ['data' => ['paid' => 1, 'status_text' => 'paid', 'total' => '4925.75', 'currency' => 'SR', 'intent_key' => 'i', 'transaction_id' => 0]];

        $usd = $mapper->statusResult('PAY-1', $response, Money::fromDecimal('100.00', Currency::fromIso('USD')));
        self::assertNull($usd->amount);
        self::assertSame(PaymentStatus::SUCCEEDED, $usd->status);
        self::assertSame('SAR', $usd->metadata['fawaterk_currency']);
        self::assertNull($usd->gatewayTransactionId);

        $egp = $mapper->statusResult('PAY-1', $response, Money::fromDecimal('4925.75', Currency::egp()));
        self::assertSame(492575, $egp->amount?->minorAmount);
    }

    public function test_an_invoice_level_failure_is_not_treated_as_final(): void
    {
        $result = (new FawaterkMapper())->statusResult('PAY-1', ['data' => ['paid' => 0, 'status_text' => 'failed', 'total' => '10.00']]);

        self::assertNull($result->status);
    }

    public function test_unknown_refund_reasons_fall_back_to_other_with_the_text_as_comment(): void
    {
        $client = new FakeFawaterkClient();
        $gateway = new FawaterkGateway($client, new FawaterkMapper(), new FawaterkSignatureVerifier(self::VENDOR_KEY));

        $result = $gateway->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-1',
            amount: Money::fromDecimal('25.00', Currency::egp()),
            reason: 'Customer cancelled the booking',
            metadata: ['gateway_transaction_id' => '555'],
        ));

        self::assertSame(RefundStatus::PENDING, $result->status);
        self::assertSame('Other', $client->refundPayload['reason']);
        self::assertSame('Customer cancelled the booking', $client->refundPayload['comment']);
        self::assertSame('25.00', (string) $client->refundPayload['refundable_amount']);
    }

    public function test_refunds_below_the_gateway_minimum_are_rejected_before_calling_it(): void
    {
        $client = new FakeFawaterkClient();
        $gateway = new FawaterkGateway($client, new FawaterkMapper(), new FawaterkSignatureVerifier(self::VENDOR_KEY));

        try {
            $gateway->refund(new RefundPaymentRequest('PAY-1', Money::fromDecimal('0.50', Currency::egp()), metadata: ['gateway_transaction_id' => '555']));
            self::fail('Expected InvalidRefundException.');
        } catch (InvalidRefundException) {
            self::assertSame([], $client->refundPayload);
        }
    }

    public function test_cart_items_must_add_up_to_the_payment_amount(): void
    {
        $gateway = new FawaterkGateway(new FakeFawaterkClient(), new FawaterkMapper(), new FawaterkSignatureVerifier(self::VENDOR_KEY));

        $this->expectException(\InvalidArgumentException::class);
        $gateway->createPayment(new CreatePaymentRequest(
            'PAY-CART',
            Money::fromDecimal('100.00', Currency::egp()),
            new CustomerData(name: 'Ahmed Ali'),
            metadata: ['cart_items' => [['name' => 'Seat', 'price' => '40.00', 'quantity' => 2]]],
        ));
    }

    /** @return array{0: PaymentService, 1: InMemoryPaymentRepository} */
    private function service(FakeFawaterkClient $client): array
    {
        $payments = new InMemoryPaymentRepository();
        $service = new PaymentService(
            new GatewayManager([new FawaterkGateway($client, new FawaterkMapper(), new FawaterkSignatureVerifier(self::VENDOR_KEY))]),
            $payments,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
            new InMemoryRefundRepository(),
        );

        return [$service, $payments];
    }

    private function transaction(bool $paid): array
    {
        return [
            'paid' => $paid ? 1 : 0,
            'status_text' => $paid ? 'paid' : 'unpaid',
            'total' => '100.00',
            'currency' => 'EGP',
            'intent_key' => 'intent-1',
            'transaction_id' => 555,
            'pay_load' => json_encode(['payment_reference' => 'PAY-F1'], JSON_THROW_ON_ERROR),
        ];
    }
}
