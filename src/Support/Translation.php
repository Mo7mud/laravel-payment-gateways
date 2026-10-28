<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Translation lookup for package texts and config labels. A value without a
 * translation line (a custom label in a published config, a plain string) is
 * returned as is, so config entries may hold either a key or ready text.
 */
final class Translation
{
    public const NAMESPACE = 'payment-gateway';

    /** @param array<string, mixed> $replace */
    public static function get(string $key, array $replace = []): string
    {
        if ($key === '') {
            return '';
        }

        $line = Lang::get($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
