<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventStatus;
use Mo7mud\LaravelPaymentGateway\Models\WebhookEvent;

final class EloquentWebhookEventRepository implements WebhookEventRepositoryInterface
{
    public function find(string $gateway, string $eventId): ?WebhookEvent
    {
        return WebhookEvent::query()
            ->where('gateway', $gateway)
            ->where('event_id', $eventId)
            ->first();
    }

    public function findForUpdate(string $gateway, string $eventId): ?WebhookEvent
    {
        return WebhookEvent::query()
            ->where('gateway', $gateway)
            ->where('event_id', $eventId)
            ->lockForUpdate()
            ->first();
    }

    public function storeIfAbsent(
        string $gateway,
        string $eventId,
        string $eventType,
        string $paymentReference,
        string $payloadHash,
        array $payload,
        WebhookEventStatus $status,
    ): bool {
        // Lengths are validated by the caller, so insertOrIgnore cannot hide
        // truncation warnings on MySQL. The unique (gateway, event_id) index
        // turns concurrent deliveries into a single row on every driver.
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'gateway' => $gateway,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payment_reference' => $paymentReference,
            'payload_hash' => $payloadHash,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => $status->value,
            'attempts' => 1,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return true;
        }

        WebhookEvent::query()
            ->where('gateway', $gateway)
            ->where('event_id', $eventId)
            ->increment('attempts');

        return false;
    }

    public function markStatus(
        string $gateway,
        string $eventId,
        WebhookEventStatus $status,
        ?string $reason = null,
        ?string $paymentReference = null,
    ): void {
        $values = [
            'status' => $status->value,
            'status_reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'processed_at' => $status->isFinal() ? now() : null,
            'updated_at' => now(),
        ];

        if ($paymentReference !== null && $paymentReference !== '') {
            $values['payment_reference'] = $paymentReference;
        }

        $query = WebhookEvent::query()
            ->where('gateway', $gateway)
            ->where('event_id', $eventId);

        if (! $status->isFinal()) {
            // A failure or retry never overwrites an event another delivery
            // already processed (conditional update, safe under concurrency).
            $query->whereNotIn('status', [WebhookEventStatus::PROCESSED->value, WebhookEventStatus::IGNORED->value]);
        }

        $query->update($values);
    }

    public function prune(DateTimeInterface $before): int
    {
        return WebhookEvent::query()
            ->whereIn('status', [WebhookEventStatus::PROCESSED->value, WebhookEventStatus::IGNORED->value])
            ->where('created_at', '<', $before)
            ->delete();
    }
}
