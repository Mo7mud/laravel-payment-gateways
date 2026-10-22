<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Support;

/**
 * Builds the customer redirect after checkout. A per-payment URL
 * (metadata['return_redirect_url']) must point to an allowed host, so a
 * payment cannot become an open redirect.
 */
final class ReturnRedirect
{
    public const METADATA_KEY = 'return_redirect_url';

    /** @param array{reference?: string, status?: string, gateway?: string, error?: string} $values */
    public static function render(string $template, array $values): string
    {
        $replacements = [];
        foreach (['reference', 'status', 'gateway', 'error'] as $name) {
            $replacements['{' . $name . '}'] = rawurlencode((string) ($values[$name] ?? ''));
        }

        return strtr($template, $replacements);
    }

    /**
     * http(s) only, no credentials or backslashes/whitespace, and the host must be listed exactly
     * (case-insensitive). An empty list allows nothing.
     *
     * @param array<int, mixed> $allowedHosts
     */
    public static function isAllowed(string $url, array $allowedHosts): bool
    {
        // Browsers read "\" as "/", so a backslash could move the real host.
        if (preg_match('/[\\\\\s]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);
        foreach ($allowedHosts as $allowed) {
            if (is_string($allowed) && $host === strtolower(trim($allowed))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function hostsFromString(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $host): bool => $host !== ''));
    }
}
