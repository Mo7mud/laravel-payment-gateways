<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventStatus;
use Mo7mud\LaravelPaymentGateway\Models\WebhookEvent;

interface WebhookEventRepositoryInterface
{
    public function find(string $gateway, string $eventId): ?WebhookEvent;

    /** Must be called inside a transaction; locks the row until commit. */
    public function findForUpdate(string $gateway, string $eventId): ?WebhookEvent;

    /**
     * Records receipt of an event. Returns true when this call created the row,
     * false when the event already exists (duplicate delivery or retry).
     */
    public function storeIfAbsent(
        string $gateway,
        string $eventId,
        string $eventType,
        string $paymentReference,
        string $payloadHash,
        array $payload,
        WebhookEventStatus $status,
    ): bool;

    /**
     * A non-final status (received, failed) is only written while the event is
     * not yet processed or ignored.
     */
    public function markStatus(
        string $gateway,
        string $eventId,
        WebhookEventStatus $status,
        ?string $reason = null,
        ?string $paymentReference = null,
    ): void;

    /** Deletes processed/ignored events older than $before. Failed events are kept. */
    public function prune(DateTimeInterface $before): int;
}
