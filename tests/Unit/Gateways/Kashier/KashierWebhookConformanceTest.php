<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Kashier;

use Mo7mud\LaravelPaymentGateway\DTOs\RefundNotification;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventKind;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use Mo7mud\LaravelPaymentGateway\Tests\Support\FakeKashierClient;
use Mo7mud\LaravelPaymentGateway\Tests\Support\KashierPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KashierWebhookConformanceTest extends TestCase
{
    /** Worked example from Kashier's webhook documentation (Payment API Key "11111"). */
    public function test_official_documentation_example_verifies(): void
    {
        $data = [
            'amount' => 1,
            'channel' => 'online | e-commerce',
            'currency' => 'EGP',
            'kashierOrderId' => '9ad06b17-755b-4e21-9774-aff3e2726ac9',
            'merchantOrderId' => '1653481557813',
            'method' => 'card',
            'orderReference' => 'TEST-ORD-38855',
            'status' => 'SUCCESS',
            'transactionId' => 'TX-249893963',
            'transactionResponseCode' => '00',
            // Deliberately unsorted: the signer sorts keys alphabetically.
            'signatureKeys' => ['transactionId', 'amount', 'status', 'currency', 'merchantOrderId', 'method', 'orderReference', 'kashierOrderId', 'channel', 'transactionResponseCode'],
        ];

        $signed = (new KashierSignatureVerifier('11111'))->verifyWebhook(
            ['event' => 'pay', 'data' => $data],
            ['X-Kashier-Signature' => '9610477b2255b2a8ef84fd89adfaa5f1305ff9c20324205851890f1ea03109f4'],
        );

        self::assertSame('TX-249893963', $signed['transactionId']);
        self::assertArrayNotHasKey('signatureKeys', $signed);
    }

    public function test_a_tampered_signed_value_is_rejected(): void
    {
        $request = KashierPayloads::webhook('pay', 'SUCCESS', 'TX-1', 'ORD-1');
        $payload = $request->payload;
        $payload['data']['amount'] = '1';

        $this->expectException(WebhookVerificationException::class);
        (new KashierSignatureVerifier(KashierPayloads::API_KEY))->verifyWebhook($payload, $request->headers);
    }

    public function test_a_webhook_that_does_not_sign_the_amount_is_rejected(): void
    {
        $data = ['currency' => 'EGP', 'merchantOrderId' => 'ORD-1', 'status' => 'SUCCESS', 'transactionId' => 'TX-1', 'amount' => '100'];
        $data['signatureKeys'] = ['currency', 'merchantOrderId', 'status', 'transactionId'];
        $message = 'currency=EGP&merchantOrderId=ORD-1&status=SUCCESS&transactionId=TX-1';

        $this->expectException(WebhookVerificationException::class);
        (new KashierSignatureVerifier(KashierPayloads::API_KEY))->verifyWebhook(
            ['event' => 'pay', 'data' => $data],
            ['x-kashier-signature' => hash_hmac('sha256', $message, KashierPayloads::API_KEY)],
        );
    }

    public function test_redirect_signature_uses_the_fixed_field_order_and_null_for_missing_fields(): void
    {
        $query = [
            'paymentStatus' => 'SUCCESS',
            'merchantOrderId' => 'ORD-1',
            'orderId' => 'K-1',
            'transactionId' => 'TX-1',
            'amount' => '100',
            'currency' => 'EGP',
            'mode' => 'test',
        ];
        $message = 'paymentStatus=SUCCESS&cardDataToken=null&maskedCard=null&merchantOrderId=ORD-1&orderId=K-1'
            .'&cardBrand=null&orderReference=null&transactionId=TX-1&amount=100&currency=EGP';
        $query['signature'] = hash_hmac('sha256', $message, KashierPayloads::API_KEY);

        (new KashierSignatureVerifier(KashierPayloads::API_KEY))->verifyRedirect($query);
        $this->addToAssertionCount(1);

        $query['paymentStatus'] = 'FAILURE';
        $this->expectException(WebhookVerificationException::class);
        (new KashierSignatureVerifier(KashierPayloads::API_KEY))->verifyRedirect($query);
    }

    /** @return iterable<string, array{string, string, WebhookEventKind, ?PaymentStatus, ?string, ?RefundStatus}> */
    public static function events(): iterable
    {
        yield 'pay success' => ['pay', 'SUCCESS', WebhookEventKind::PAYMENT, PaymentStatus::SUCCEEDED, null, null];
        yield 'pay failure keeps the payment open' => ['pay', 'FAILURE', WebhookEventKind::ATTEMPT_FAILED, null, null, null];
        yield 'pay pending' => ['pay', 'PENDING', WebhookEventKind::PAYMENT, PaymentStatus::PENDING, null, null];
        yield 'authorize success is not a capture' => ['authorize', 'SUCCESS', WebhookEventKind::PAYMENT, PaymentStatus::PROCESSING, null, null];
        yield 'capture success' => ['capture', 'SUCCESS', WebhookEventKind::PAYMENT, PaymentStatus::SUCCEEDED, null, null];
        yield 'void success is not a payment' => ['void', 'SUCCESS', WebhookEventKind::REFUND, null, RefundNotification::TYPE_VOID, RefundStatus::SUCCEEDED];
        yield 'reversal success is not a payment' => ['reversal', 'SUCCESS', WebhookEventKind::REFUND, null, RefundNotification::TYPE_REVERSAL, RefundStatus::SUCCEEDED];
        yield 'partial refund' => ['partial_refund', 'SUCCESS', WebhookEventKind::REFUND, null, RefundNotification::TYPE_REFUND, RefundStatus::SUCCEEDED];
        yield 'pending refund' => ['refund', 'PENDING', WebhookEventKind::REFUND, null, RefundNotification::TYPE_REFUND, RefundStatus::PENDING];
        // The fixture leaves "event" unsigned (top level), as Kashier does unless it lists it in signatureKeys.
        yield 'unsigned reject is not trusted' => ['reject', 'SUCCESS', WebhookEventKind::IGNORED, null, null, null];
        yield 'unknown event' => ['chargeback', 'SUCCESS', WebhookEventKind::IGNORED, null, null, null];
    }

    #[DataProvider('events')]
    public function test_event_and_status_are_mapped_together(
        string $event,
        string $status,
        WebhookEventKind $kind,
        ?PaymentStatus $paymentStatus,
        ?string $refundType,
        ?RefundStatus $refundStatus,
    ): void {
        $result = $this->gateway()->handleWebhook(KashierPayloads::webhook($event, $status, 'TX-9', 'ORD-1', '25.50'));

        self::assertSame($kind, $result->kind);
        self::assertSame($paymentStatus, $result->status);
        self::assertSame('ORD-1', $result->paymentReference);
        self::assertTrue($result->referenceTrusted);
        self::assertSame(2550, $result->amount?->minorAmount);
        self::assertSame($refundType, $result->refund?->type);
        self::assertSame($refundStatus, $result->refund?->status);

        if ($kind === WebhookEventKind::PAYMENT || $kind === WebhookEventKind::REFUND) {
            self::assertTrue($result->requiresVerification);
        }
    }

    public function test_a_signed_reject_event_is_an_attempt_failure(): void
    {
        $result = $this->gateway()->handleWebhook(
            KashierPayloads::webhook('reject', 'SUCCESS', 'TX-9', 'ORD-1', '25.50', ['event' => 'reject']),
        );

        self::assertSame(WebhookEventKind::ATTEMPT_FAILED, $result->kind);
    }

    public function test_a_redirect_signed_over_all_returned_parameters_is_accepted(): void
    {
        $query = [
            'paymentStatus' => 'SUCCESS',
            'merchantOrderId' => 'ORD-1',
            'orderId' => 'K-1',
            'transactionId' => 'TX-1',
            'amount' => '100',
            'currency' => 'EGP',
            'installment' => '0',
            'mode' => 'test',
        ];
        $message = 'paymentStatus=SUCCESS&merchantOrderId=ORD-1&orderId=K-1&transactionId=TX-1&amount=100&currency=EGP&installment=0';
        $query['signature'] = hash_hmac('sha256', $message, KashierPayloads::API_KEY);

        (new KashierSignatureVerifier(KashierPayloads::API_KEY))->verifyRedirect($query);
        $this->addToAssertionCount(1);

        $query['amount'] = '1';
        $this->expectException(WebhookVerificationException::class);
        (new KashierSignatureVerifier(KashierPayloads::API_KEY))->verifyRedirect($query);
    }

    public function test_unsigned_fields_cannot_change_the_outcome(): void
    {
        $request = KashierPayloads::webhook('pay', 'FAILURE', 'TX-1', 'ORD-1');
        $payload = $request->payload;
        // Not in signatureKeys: ignored by design.
        $payload['data']['orderStatus'] = 'SUCCESS';
        $payload['data']['merchantOrderIdOverride'] = 'ORD-OTHER';

        $result = $this->gateway()->handleWebhook(new WebhookRequest($payload, $request->headers));

        self::assertSame(WebhookEventKind::ATTEMPT_FAILED, $result->kind);
        self::assertSame('ORD-1', $result->paymentReference);
    }

    public function test_event_ids_follow_kashier_deduplication_key(): void
    {
        $gateway = $this->gateway();

        $first = $gateway->handleWebhook(KashierPayloads::webhook('pay', 'FAILURE', 'TX-1', 'ORD-1'));
        $second = $gateway->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-1', 'ORD-1'));

        self::assertNotSame($first->eventId, $second->eventId);
        self::assertLessThanOrEqual(191, strlen($second->eventId));
    }

    private function gateway(): KashierGateway
    {
        return new KashierGateway(
            new FakeKashierClient,
            new KashierSignatureVerifier(KashierPayloads::API_KEY),
            new KashierStatusMapper,
            ['merchant_id' => 'MID-TEST', 'return_url' => 'https://shop.test/return'],
        );
    }
}
