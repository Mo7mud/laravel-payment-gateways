<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\ValueObjects;

use Mo7mud\LaravelPaymentGateway\ValueObjects\GatewayCapabilities;
use PHPUnit\Framework\TestCase;

final class GatewayCapabilitiesTest extends TestCase
{
    public function test_capabilities_default_to_unsupported(): void
    {
        $capabilities = new GatewayCapabilities();

        self::assertFalse($capabilities->supportsHostedCheckout());
        self::assertFalse($capabilities->supportsStatusQuery());
        self::assertFalse($capabilities->supportsReconciliation());
        self::assertFalse($capabilities->supportsWebhooks());
        self::assertFalse($capabilities->supportsRefunds());
        self::assertFalse($capabilities->supportsPartialRefunds());
    }

    public function test_partial_refund_is_an_independent_capability(): void
    {
        $capabilities = new GatewayCapabilities(
            reconciliation: true,
            refunds: true,
            partialRefunds: false,
        );

        self::assertTrue($capabilities->supportsReconciliation());
        self::assertTrue($capabilities->supportsRefunds());
        self::assertFalse($capabilities->supportsPartialRefunds());
    }
}
