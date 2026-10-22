<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier;

/**
 * Optional capability of a Kashier client: paged order search. Kept separate
 * from KashierClientInterface so existing client fakes remain valid.
 */
interface KashierPaginatedOrdersInterface
{
    public function getOrdersPage(string $merchantOrderId, int $page, int $limit = 20): array;
}
