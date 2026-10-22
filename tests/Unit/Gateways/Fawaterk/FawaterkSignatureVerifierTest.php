<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Fawaterk;

use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class FawaterkSignatureVerifierTest extends TestCase
{
    private const KEY = 'vendor-secret';

    public function test_paid_signature_is_verified(): void
    {
        $payload = [
            'transaction_id' => 12345,
            'transaction_key' => 'intent-123',
            'payment_method' => 'Visa-Mastercard',
            'transactionHashKey' => hash_hmac('sha256', 'TransactionId=12345&TransactionKey=intent-123&PaymentMethod=Visa-Mastercard', self::KEY),
        ];

        (new FawaterkSignatureVerifier(self::KEY))->verifyPaidOrPending($payload);
        self::assertTrue(true);
    }

    public function test_failed_signature_is_verified(): void
    {
        $payload = [
            'transaction_id' => 12345,
            'transaction_key' => 'intent-123',
            'payment_method' => 'Fawry',
            'hashKey' => hash_hmac('sha256', 'TransactionId=12345&TransactionKey=intent-123&PaymentMethod=Fawry', self::KEY),
        ];

        (new FawaterkSignatureVerifier(self::KEY))->verifyFailed($payload);
        self::assertTrue(true);
    }

    public function test_cancel_signature_is_verified(): void
    {
        $payload = [
            'referenceId' => 987,
            'paymentMethod' => 'Aman',
            'hashKey' => hash_hmac('sha256', 'referenceId=987&PaymentMethod=Aman', self::KEY),
        ];

        (new FawaterkSignatureVerifier(self::KEY))->verifyCancel($payload);
        self::assertTrue(true);
    }

    public function test_refund_signature_is_verified(): void
    {
        $payload = [
            'transactionId' => 12345,
            'amount' => '50.00',
            'currency' => 'EGP',
            'hashKey' => hash_hmac('sha256', 'transactionId=12345&amount=50.00&currency=EGP', self::KEY),
        ];

        (new FawaterkSignatureVerifier(self::KEY))->verifyRefund($payload);
        self::assertTrue(true);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->expectException(WebhookVerificationException::class);

        (new FawaterkSignatureVerifier(self::KEY))->verifyPaidOrPending([
            'transaction_id' => 12345,
            'transaction_key' => 'intent-123',
            'payment_method' => 'Visa-Mastercard',
            'transactionHashKey' => 'invalid',
        ]);
    }
}
