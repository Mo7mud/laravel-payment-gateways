<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use DateTimeImmutable;

final readonly class WebhookRequest
{
    /**
     * @param string|null $type Notification type taken from the route the gateway
     *        called (e.g. Fawaterk "paid", "failed", "cancel", "refund"). Trusted
     *        because the merchant configures one URL per type.
     * @param string $rawBody The request body exactly as received. Gateways that sign
     *        the raw bytes (e.g. PayTabs, Stripe) must verify against this, never
     *        against a re-encoded payload.
     * @param array<string, mixed> $query Query-string parameters of the notification
     *        URL. Some gateways send the signature there (e.g. Paymob's "hmac").
     */
    public function __construct(
        public array $payload,
        public array $headers = [],
        public ?DateTimeImmutable $receivedAt = null,
        public ?string $type = null,
        public string $rawBody = '',
        public array $query = [],
    ) {
    }
}
