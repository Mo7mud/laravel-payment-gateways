<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier;

use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;

/**
 * Kashier signatures (HMAC-SHA256 with the Payment API Key).
 *
 * Webhook: the keys listed in data.signatureKeys are sorted alphabetically,
 * values are URL-encoded and joined as a query string (the "query-string"
 * npm package behaviour used by Kashier's reference implementation).
 *
 * Redirect: a fixed, ordered list of fields (absent values become "null"),
 * or every returned parameter except signature/mode in received order.
 */
final class KashierSignatureVerifier
{
    /** Fields the package relies on; a webhook that does not sign them is rejected. */
    public const DEFAULT_REQUIRED_KEYS = ['amount', 'currency', 'merchantOrderId', 'status', 'transactionId'];

    private const REDIRECT_FIELDS = [
        'paymentStatus', 'cardDataToken', 'maskedCard', 'merchantOrderId', 'orderId',
        'cardBrand', 'orderReference', 'transactionId', 'amount', 'currency',
    ];

    /** @param list<string> $requiredSignedKeys */
    public function __construct(
        private readonly string $paymentApiKey,
        private readonly array $requiredSignedKeys = self::DEFAULT_REQUIRED_KEYS,
    ) {}

    /**
     * @return array<string, scalar|null> Only the signed fields. Callers must not
     *                                    read decision-relevant values from anywhere else in the payload.
     */
    public function verifyWebhook(array $payload, array $headers): array
    {
        $this->assertConfigured();

        $signature = $this->header($headers, 'x-kashier-signature');
        if ($signature === null || trim($signature) === '') {
            throw new WebhookVerificationException('Missing Kashier webhook signature.');
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            throw new WebhookVerificationException('Kashier webhook payload has no data object.');
        }

        $keys = $data['signatureKeys'] ?? null;
        if (! is_array($keys) || $keys === []) {
            throw new WebhookVerificationException('Kashier webhook does not list signatureKeys.');
        }

        $names = [];
        foreach ($keys as $key) {
            if (! is_string($key) || $key === '' || $key === 'signatureKeys') {
                throw new WebhookVerificationException('Kashier webhook has an invalid signature key.');
            }
            $names[$key] = true;
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);

        $parts = [];
        $signed = [];
        foreach ($names as $name) {
            if (! array_key_exists($name, $data)) {
                continue; // undefined values are skipped by the signer
            }

            $value = $data[$name];
            if (is_array($value) || is_object($value)) {
                throw new WebhookVerificationException(sprintf('Kashier webhook signs nested field [%s], which is not supported.', $name));
            }

            $signed[$name] = $value;
            $parts[] = $value === null
                ? rawurlencode($name)
                : rawurlencode($name).'='.rawurlencode($this->stringify($value));
        }

        foreach ($this->requiredSignedKeys as $required) {
            if (! isset($signed[$required]) || $signed[$required] === '') {
                throw new WebhookVerificationException(sprintf('Kashier webhook does not sign required field [%s].', $required));
            }
        }

        $expected = hash_hmac('sha256', implode('&', $parts), $this->paymentApiKey);
        if (! hash_equals($expected, strtolower(trim($signature)))) {
            throw new WebhookVerificationException('Invalid Kashier webhook signature.');
        }

        return $signed;
    }

    public function verifyRedirect(array $query): void
    {
        $this->assertConfigured();

        $signature = $query['signature'] ?? null;
        if (! is_string($signature) || trim($signature) === '') {
            throw new WebhookVerificationException('Missing Kashier redirect signature.');
        }

        $signature = strtolower(trim($signature));

        // Payment Sessions sign a fixed, ordered field list (absent values as "null").
        $parts = [];
        foreach (self::REDIRECT_FIELDS as $field) {
            $value = $query[$field] ?? null;
            if (is_array($value)) {
                throw new WebhookVerificationException('Invalid Kashier redirect parameters.');
            }
            $parts[] = $field.'='.($value === null ? 'null' : (string) $value);
        }

        if (hash_equals(hash_hmac('sha256', implode('&', $parts), $this->paymentApiKey), $signature)) {
            return;
        }

        // Kashier's hosted-page sample signs every returned parameter except
        // signature and mode, in the order received. Accepting it as well keeps
        // returns valid when Kashier adds or omits a field.
        $parts = [];
        foreach ($query as $key => $value) {
            if ($key === 'signature' || $key === 'mode') {
                continue;
            }
            if (is_array($value)) {
                throw new WebhookVerificationException('Invalid Kashier redirect parameters.');
            }
            $parts[] = $key.'='.($value === null ? '' : (string) $value);
        }

        if ($parts === [] || ! hash_equals(hash_hmac('sha256', implode('&', $parts), $this->paymentApiKey), $signature)) {
            throw new WebhookVerificationException('Invalid Kashier redirect signature.');
        }
    }

    private function assertConfigured(): void
    {
        if ($this->paymentApiKey === '') {
            throw new WebhookVerificationException('Kashier Payment API Key is not configured.');
        }
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== strtolower($name)) {
                continue;
            }

            if (is_array($value)) {
                return isset($value[0]) ? (string) $value[0] : null;
            }

            return (string) $value;
        }

        return null;
    }
}
