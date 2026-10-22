<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

/**
 * Refunds of one payment as reported by the gateway API.
 *
 * @property-read list<GatewayRefundRecord> $refunds oldest first
 */
final readonly class GatewayRefundList
{
    /**
     * @param list<GatewayRefundRecord> $refunds
     * @param bool $complete true when every page was read, so a local refund
     *        missing from the list can be reported as unknown to the gateway
     */
    public function __construct(
        public array $refunds,
        public bool $complete,
    ) {
    }
}
