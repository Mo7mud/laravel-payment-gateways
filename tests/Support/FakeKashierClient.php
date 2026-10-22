<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Support;

use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierApiException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierClientInterface;

/** Scriptable Kashier API: session status, order search results and failures. */
final class FakeKashierClient implements KashierClientInterface
{
    public int $sessions = 0;
    public string $sessionStatus = 'OPENED';
    public bool $failCreate = false;
    /** @var list<string> merchant order ids sent to createPaymentSession */
    public array $createdOrders = [];
    /** @var list<array<string, mixed>> */
    public array $orders = [];

    public function createPaymentSession(array $payload): array
    {
        $this->createdOrders[] = (string) $payload['order'];

        if ($this->failCreate) {
            throw new KashierApiException('Kashier API request failed: timeout', 0);
        }

        $this->sessions++;

        return [
            '_id' => 'SESSION-' . $this->sessions,
            'sessionUrl' => 'https://checkout.kashier.test/SESSION-' . $this->sessions,
            'status' => 'CREATED',
        ];
    }

    public function getPaymentSession(string $sessionId): array
    {
        $paid = $this->sessionStatus === 'PAID';

        return ['data' => [
            'sessionId' => $sessionId,
            'status' => $this->sessionStatus,
            'merchantOrderId' => $this->createdOrders[array_key_last($this->createdOrders)] ?? null,
            'amount' => 100,
            'currency' => 'EGP',
            'capturedAmount' => $paid ? 100 : 0,
            'refundedAmount' => 0,
            'targetTransactionId' => $paid ? 'TX-OK' : null,
        ]];
    }

    public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array
    {
        return ['status' => 'SUCCESS', 'data' => $this->orders];
    }

    public function refund(string $orderId, ?string $amount, ?string $reason): array
    {
        return ['status' => 'SUCCESS', 'response' => ['status' => 'SUCCESS', 'amount' => $amount, 'currency' => 'EGP', 'transactionId' => 'TX-REFUND']];
    }
}
