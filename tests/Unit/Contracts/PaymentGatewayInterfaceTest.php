<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Contracts;

use Mo7mud\LaravelPaymentGateway\Contracts\PaymentGatewayInterface;
use Mo7mud\LaravelPaymentGateway\Enums\GatewayName;
use Mo7mud\LaravelPaymentGateway\ValueObjects\GatewayCapabilities;
use PHPUnit\Framework\TestCase;

final class PaymentGatewayInterfaceTest extends TestCase
{
    public function test_a_gateway_exposes_only_generic_identity_and_capabilities_at_the_base_contract(): void
    {
        $gateway = new class implements PaymentGatewayInterface
        {
            public function name(): GatewayName
            {
                return GatewayName::KASHIER;
            }

            public function capabilities(): GatewayCapabilities
            {
                return new GatewayCapabilities(
                    hostedCheckout: true,
                    statusQuery: true,
                    webhooks: true,
                    refunds: true,
                    partialRefunds: true,
                );
            }
        };

        self::assertSame(GatewayName::KASHIER, $gateway->name());
        self::assertTrue($gateway->capabilities()->supportsHostedCheckout());
        self::assertTrue($gateway->capabilities()->supportsStatusQuery());
        self::assertTrue($gateway->capabilities()->supportsWebhooks());
        self::assertTrue($gateway->capabilities()->supportsRefunds());
        self::assertTrue($gateway->capabilities()->supportsPartialRefunds());
    }
}
