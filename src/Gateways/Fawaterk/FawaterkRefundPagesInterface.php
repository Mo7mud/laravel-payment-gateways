<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk;

/**
 * Optional capability of a Fawaterk client: one page of POST /api/v3/refund/index.
 * Kept separate from FawaterkClientInterface so existing client fakes stay valid.
 */
interface FawaterkRefundPagesInterface
{
    /** @return array the raw Laravel paginator response (data, current_page, last_page...) */
    public function listRefundsPage(int $page): array;
}
