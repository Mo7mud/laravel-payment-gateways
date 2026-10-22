<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Enums;

/**
 * Built-in gateway keys. Third-party gateways are not limited to this enum:
 * PaymentGatewayInterface::name() may return a plain string key.
 */
enum GatewayName: string
{
    case KASHIER = 'kashier';
    case FAWATERK = 'fawaterk';
    /** @deprecated Reserved name; no implementation ships with the package. */
    case PAYMOB = 'paymob';
    /** @deprecated Reserved name; no implementation ships with the package. */
    case FAWRY = 'fawry';
    /** @deprecated Reserved name; no implementation ships with the package. */
    case STRIPE = 'stripe';
    /** Test double, see Testing\MockGateway. */
    case MOCK = 'mock';

    public static function keyOf(self|string $name): string
    {
        return $name instanceof self ? $name->value : strtolower(trim($name));
    }
}
