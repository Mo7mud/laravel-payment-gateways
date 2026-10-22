<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Support;

/**
 * Removes personal data and signing material from gateway payloads before
 * they are persisted. Matching is case-insensitive on array keys, at any depth.
 */
final class PayloadRedactor
{
    public const DEFAULT_KEYS = [
        'card', 'cardInfo', 'cardHolderName', 'maskedCard', 'cardDataToken', 'ccvToken',
        'sourceOfFunds', 'customerData', 'customer', 'customer_email', 'customer_phone',
        'customer_first_name', 'customer_last_name', 'email', 'phone', 'mobileWalletNumber',
        'merchantDetails', 'hash', 'hashKey', 'transactionHashKey', 'api_key', 'apiKey',
        'paymentToken', 'signature',
    ];

    /** @var array<string, true> */
    private array $keys;

    /** @param list<string> $keys */
    public function __construct(array $keys = self::DEFAULT_KEYS)
    {
        $this->keys = [];
        foreach ($keys as $key) {
            $this->keys[strtolower((string) $key)] = true;
        }
    }

    public function redact(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && isset($this->keys[strtolower($key)])) {
                $clean[$key] = '[redacted]';
                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }

    /**
     * Keeps only scalar values, so gateway metadata cannot grow without bound
     * or smuggle nested personal data into the payment record.
     */
    public static function scalarsOnly(array $metadata): array
    {
        return array_filter(
            $metadata,
            static fn (mixed $value): bool => $value === null || is_scalar($value),
        );
    }
}
