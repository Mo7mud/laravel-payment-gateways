<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Support;

/**
 * json_encode() that writes DecimalNumber values as exact JSON numbers.
 */
final class Json
{
    public static function encode(array $payload): string
    {
        $token = 'DECIMAL_'.bin2hex(random_bytes(8)).'_';
        $replacements = [];

        $prepared = self::prepare($payload, $token, $replacements);
        $json = json_encode($prepared, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Each placeholder is a unique quoted string, so only those are replaced.
        return strtr($json, $replacements);
    }

    public static function containsDecimals(array $payload): bool
    {
        foreach ($payload as $value) {
            if ($value instanceof DecimalNumber || (is_array($value) && self::containsDecimals($value))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $replacements */
    private static function prepare(array $payload, string $token, array &$replacements): array
    {
        foreach ($payload as $key => $value) {
            if ($value instanceof DecimalNumber) {
                $placeholder = $token.count($replacements);
                $replacements['"'.$placeholder.'"'] = $value->value;
                $payload[$key] = $placeholder;
            } elseif (is_array($value)) {
                $payload[$key] = self::prepare($value, $token, $replacements);
            }
        }

        return $payload;
    }
}
