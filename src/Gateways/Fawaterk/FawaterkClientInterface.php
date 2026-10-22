<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk;

interface FawaterkClientInterface
{
    public function getPaymentMethods(): array;

    public function createTransaction(array $payload): array;

    public function getTransactionData(string $intentKey): array;

    public function getTransactionsData(string $startDate, string $endDate, int $perPage = 15, ?string $payloadFilter = null, int $page = 1): array;

    public function getRefundTypes(?string $lang = null): array;

    public function getRefundReasons(?string $lang = null): array;

    public function createRefund(array $payload): array;

    public function deleteRefund(int $refundId): array;

    public function listRefunds(): array;

    public function getRefundDetails(int $refundId): array;
}
