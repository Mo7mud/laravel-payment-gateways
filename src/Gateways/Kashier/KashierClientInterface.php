<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier;

interface KashierClientInterface
{
    public function createPaymentSession(array $payload): array;

    public function getPaymentSession(string $sessionId): array;

    public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array;

    public function refund(string $orderId, ?string $amount, ?string $reason): array;
}
