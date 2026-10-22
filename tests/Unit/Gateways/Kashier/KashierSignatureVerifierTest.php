<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Kashier;

use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class KashierSignatureVerifierTest extends TestCase
{
    public function test_transaction_signature_is_verified_using_signature_keys_order(): void
    {
        $apiKey = 'test-payment-key';
        $data = [
            'amount' => 100,
            'currency' => 'EGP',
            'kashierOrderId' => 'K-1',
            'merchantOrderId' => 'ORDER-1',
            'status' => 'SUCCESS',
            'transactionId' => 'TX-1',
            'signatureKeys' => ['amount', 'currency', 'kashierOrderId', 'merchantOrderId', 'status', 'transactionId'],
        ];

        $message = http_build_query([
            'amount' => 100,
            'currency' => 'EGP',
            'kashierOrderId' => 'K-1',
            'merchantOrderId' => 'ORDER-1',
            'status' => 'SUCCESS',
            'transactionId' => 'TX-1',
        ], '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $message, $apiKey);

        (new KashierSignatureVerifier($apiKey))->verifyWebhook(
            ['event' => 'pay', 'data' => $data],
            ['x-kashier-signature' => $signature],
        );
        $this->addToAssertionCount(1);
    }

    public function test_boolean_signature_value_is_serialized_as_true_or_false(): void
    {
        $apiKey = 'test-payment-key';
        $data = [
            'amount' => 100,
            'channel' => 'Card',
            'creationDate' => '2026-08-15T07:56:16.412Z',
            'currency' => 'EGP',
            'isTestWebhook' => true,
            'kashierOrderId' => 'TEST-KASHIER-ORDER-0001',
            'merchantId' => 'MID-TEST',
            'merchantOrderId' => 'TEST-ORDER-0001',
            'method' => 'card',
            'orderReference' => 'TEST-ORD-REF-0001',
            'paymentMethod' => 'card',
            'status' => 'SUCCESS',
            'transactionId' => 'TEST-TRX-0001',
            'transactionResponseCode' => '00',
            'signatureKeys' => [
                'amount', 'channel', 'creationDate', 'currency', 'isTestWebhook',
                'kashierOrderId', 'merchantId', 'merchantOrderId', 'method',
                'orderReference', 'paymentMethod', 'status', 'transactionId',
                'transactionResponseCode',
            ],
        ];

        $parts = [];
        foreach ($data['signatureKeys'] as $key) {
            $value = $data[$key];
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $parts[$key] = $value;
        }

        $message = http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $message, $apiKey);

        (new KashierSignatureVerifier($apiKey))->verifyWebhook(
            ['event' => 'pay', 'data' => $data],
            ['x-kashier-signature' => $signature],
        );
        $this->addToAssertionCount(1);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->expectException(WebhookVerificationException::class);

        (new KashierSignatureVerifier('test-payment-key'))->verifyWebhook(
            [
                'event' => 'pay',
                'data' => [
                    'amount' => 100,
                    'currency' => 'EGP',
                    'kashierOrderId' => 'K-1',
                    'merchantOrderId' => 'ORDER-1',
                    'status' => 'SUCCESS',
                    'transactionId' => 'TX-1',
                    'signatureKeys' => ['amount', 'currency', 'kashierOrderId', 'merchantOrderId', 'status', 'transactionId'],
                ],
            ],
            ['x-kashier-signature' => 'invalid'],
        );
    }
}
