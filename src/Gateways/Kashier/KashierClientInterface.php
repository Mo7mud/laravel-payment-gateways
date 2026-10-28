<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier;

interface KashierClientInterface
{
    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function createPaymentSession(array $payload): array;

    /**
     * @return array<mixed>
     */
    public function getPaymentSession(string $sessionId): array;

    /**
     * @return array<mixed>
     */
    public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array;

    /**
     * @return array<mixed>
     */
    public function refund(string $orderId, ?string $amount, ?string $reason): array;
}
