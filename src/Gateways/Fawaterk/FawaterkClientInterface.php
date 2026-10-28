<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk;

interface FawaterkClientInterface
{
    /**
     * @return array<mixed>
     */
    public function getPaymentMethods(): array;

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function createTransaction(array $payload): array;

    /**
     * @return array<mixed>
     */
    public function getTransactionData(string $intentKey): array;

    /**
     * @return array<mixed>
     */
    public function getTransactionsData(string $startDate, string $endDate, int $perPage = 15, ?string $payloadFilter = null, int $page = 1): array;

    /**
     * @return array<mixed>
     */
    public function getRefundTypes(?string $lang = null): array;

    /**
     * @return array<mixed>
     */
    public function getRefundReasons(?string $lang = null): array;

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function createRefund(array $payload): array;

    /**
     * @return array<mixed>
     */
    public function deleteRefund(int $refundId): array;

    /**
     * @return array<mixed>
     */
    public function listRefunds(): array;

    /**
     * @return array<mixed>
     */
    public function getRefundDetails(int $refundId): array;
}
