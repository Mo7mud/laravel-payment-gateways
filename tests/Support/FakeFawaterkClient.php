<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Support;

use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkClientInterface;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkRefundPagesInterface;

final class FakeFawaterkClient implements FawaterkClientInterface, FawaterkRefundPagesInterface
{
    /** @var list<array<string, mixed>> items of POST /api/v3/refund/index, one page */
    public array $refundItems = [];
    public int $refundCalls = 0;

    public array $transaction = [];
    public array $refundPayload = [];
    /** @var list<string> */
    public array $reasons = ['Product damaged', 'Other'];

    public function getPaymentMethods(): array
    {
        return [];
    }

    public function createTransaction(array $payload): array
    {
        return ['status' => 'success', 'data' => [
            'intent_key' => 'intent-1',
            'url' => 'https://staging.fawaterk.test/ts/intent-1',
            'expires_in' => 3600,
        ]];
    }

    public function getTransactionData(string $intentKey): array
    {
        return ['status' => 'success', 'data' => $this->transaction];
    }

    public function getTransactionsData(string $startDate, string $endDate, int $perPage = 15, ?string $payloadFilter = null, int $page = 1): array
    {
        return [];
    }

    public function getRefundTypes(?string $lang = null): array
    {
        return [];
    }

    public function getRefundReasons(?string $lang = null): array
    {
        return $this->reasons;
    }

    public function createRefund(array $payload): array
    {
        $this->refundCalls++;
        $this->refundPayload = $payload;

        return ['status' => 'success'];
    }

    public function deleteRefund(int $refundId): array
    {
        return [];
    }

    public function listRefunds(): array
    {
        return $this->listRefundsPage(1);
    }

    public function listRefundsPage(int $page): array
    {
        return ['current_page' => $page, 'last_page' => 1, 'per_page' => 10, 'data' => $page === 1 ? $this->refundItems : []];
    }

    public function getRefundDetails(int $refundId): array
    {
        return [];
    }
}
