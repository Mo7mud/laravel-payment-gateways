<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier;

/**
 * Optional client capability (kept separate so existing KashierClientInterface
 * implementations keep working): PATCH /v3/payment/sessions/{id} abandons an
 * open session; a session in a final state is left unchanged.
 */
interface KashierSessionCancellationInterface
{
    /**
     * @return array<mixed>
     */
    public function abandonPaymentSession(string $sessionId): array;
}
