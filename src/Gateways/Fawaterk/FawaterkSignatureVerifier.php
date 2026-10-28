<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk;

use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\Exceptions\FawaterkConfigurationException;

final class FawaterkSignatureVerifier
{
    public function __construct(private readonly string $vendorApiKey) {}

    /**
     * @param  array<mixed>  $payload
     */
    public function verifyPaidOrPending(array $payload): void
    {
        $provided = (string) ($payload['transactionHashKey'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? '');
        $transactionKey = (string) ($payload['transaction_key'] ?? '');
        $paymentMethod = (string) ($payload['payment_method'] ?? '');

        $this->verify($provided, implode('&', [
            'TransactionId='.$transactionId,
            'TransactionKey='.$transactionKey,
            'PaymentMethod='.$paymentMethod,
        ]));
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function verifyFailed(array $payload): void
    {
        $provided = (string) ($payload['hashKey'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? '');
        $transactionKey = (string) ($payload['transaction_key'] ?? '');
        $paymentMethod = (string) ($payload['payment_method'] ?? '');

        $this->verify($provided, implode('&', [
            'TransactionId='.$transactionId,
            'TransactionKey='.$transactionKey,
            'PaymentMethod='.$paymentMethod,
        ]));
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function verifyCancel(array $payload): void
    {
        $provided = (string) ($payload['hashKey'] ?? '');
        $referenceId = (string) ($payload['referenceId'] ?? '');
        $paymentMethod = (string) ($payload['paymentMethod'] ?? '');

        $this->verify($provided, implode('&', [
            'referenceId='.$referenceId,
            'PaymentMethod='.$paymentMethod,
        ]));
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function verifyRefund(array $payload): void
    {
        $provided = (string) ($payload['hashKey'] ?? '');
        $transactionId = (string) ($payload['transactionId'] ?? '');
        $amount = (string) ($payload['amount'] ?? '');
        $currency = (string) ($payload['currency'] ?? '');

        $this->verify($provided, implode('&', [
            'transactionId='.$transactionId,
            'amount='.$amount,
            'currency='.$currency,
        ]));
    }

    private function verify(string $provided, string $stringToSign): void
    {
        if ($this->vendorApiKey === '') {
            throw new FawaterkConfigurationException('Fawaterk Vendor API Key is required for webhook verification.');
        }

        if (trim($provided) === '') {
            throw new WebhookVerificationException('Fawaterk webhook signature is missing.');
        }

        $expected = hash_hmac('sha256', $stringToSign, $this->vendorApiKey);
        if (! hash_equals($expected, strtolower(trim($provided)))) {
            throw new WebhookVerificationException('Invalid Fawaterk webhook signature.');
        }
    }
}
