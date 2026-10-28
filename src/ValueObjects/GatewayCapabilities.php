<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\ValueObjects;

final readonly class GatewayCapabilities
{
    public function __construct(
        public bool $hostedCheckout = false,
        public bool $statusQuery = false,
        public bool $reconciliation = false,
        public bool $webhooks = false,
        public bool $refunds = false,
        public bool $partialRefunds = false,
        public bool $returnHandling = false,
        public bool $cancellation = false,
    ) {}

    public function supportsHostedCheckout(): bool
    {
        return $this->hostedCheckout;
    }

    public function supportsStatusQuery(): bool
    {
        return $this->statusQuery;
    }

    public function supportsReconciliation(): bool
    {
        return $this->reconciliation;
    }

    public function supportsWebhooks(): bool
    {
        return $this->webhooks;
    }

    public function supportsRefunds(): bool
    {
        return $this->refunds;
    }

    public function supportsPartialRefunds(): bool
    {
        return $this->partialRefunds;
    }

    public function supportsReturnHandling(): bool
    {
        return $this->returnHandling;
    }

    public function supportsCancellation(): bool
    {
        return $this->cancellation;
    }
}
