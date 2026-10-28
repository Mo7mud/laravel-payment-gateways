<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Support;

use Illuminate\Container\Container;
use Throwable;

/**
 * Safe access to package configuration, including outside a booted Laravel
 * application (plain PHPUnit tests, standalone scripts).
 */
final class PackageConfig
{
    private const TABLE_DEFAULTS = [
        // Prefixed so they never collide with an application's own `payments` table.
        'payments' => 'gateway_payments',
        'refunds' => 'gateway_payment_refunds',
        'webhook_events' => 'gateway_payment_webhook_events',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $container = Container::getInstance();
            if ($container->bound('config')) {
                return $container->make('config')->get('payment-gateway.'.$key, $default);
            }
        } catch (Throwable) {
            // Fall through to the default.
        }

        return $default;
    }

    public static function table(string $name): string
    {
        $default = self::TABLE_DEFAULTS[$name] ?? $name;
        $value = self::get('tables.'.$name, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
