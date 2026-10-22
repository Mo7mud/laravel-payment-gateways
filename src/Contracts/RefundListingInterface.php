<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Mo7mud\LaravelPaymentGateway\DTOs\GatewayRefundList;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;

/**
 * Gateways that can list the refunds of a payment from their API. PaymentService
 * uses this as the source of truth for refunds whenever the gateway's refund
 * notifications cannot be trusted on their own (e.g. Fawaterk: no refund id,
 * status and approval time are not signed).
 */
interface RefundListingInterface
{
    public function listRefunds(PaymentStatusRequest $request): GatewayRefundList;
}
