<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Events;

/**
 * The confirmed refunded total changed. Also raised for refunds made outside
 * the application (gateway dashboard, reversals), so listeners can keep
 * orders and entitlements in sync.
 */
final class PaymentRefunded extends PaymentEvent
{
    public function __construct(
        string $paymentReference,
        string $gateway,
        public readonly int $refundedMinor,
        public readonly string $currency,
        public readonly bool $fullyRefunded,
        public readonly string $source,
    ) {
        parent::__construct($paymentReference, $gateway);
    }
}
