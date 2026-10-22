<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventStatus;
use Mo7mud\LaravelPaymentGateway\Models\WebhookEvent;

final class InMemoryWebhookEventRepository implements WebhookEventRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $events = [];

    public function find(string $gateway, string $eventId): ?WebhookEvent
    {
        $row = $this->events[$this->key($gateway, $eventId)] ?? null;
        if ($row === null) {
            return null;
        }

        $event = new WebhookEvent();
        $event->forceFill($row);
        $event->exists = true;

        return $event;
    }

    public function findForUpdate(string $gateway, string $eventId): ?WebhookEvent
    {
        return $this->find($gateway, $eventId);
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
        $key = $this->key($gateway, $eventId);

        if (isset($this->events[$key])) {
            $this->events[$key]['attempts']++;

            return false;
        }

        $this->events[$key] = [
            'gateway' => $gateway,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payment_reference' => $paymentReference,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'status' => $status,
            'status_reason' => null,
            'attempts' => 1,
            'processed_at' => null,
            'created_at' => new DateTimeImmutable(),
        ];

        return true;
    }

    public function markStatus(
        string $gateway,
        string $eventId,
        WebhookEventStatus $status,
        ?string $reason = null,
        ?string $paymentReference = null,
    ): void {
        $key = $this->key($gateway, $eventId);
        if (!isset($this->events[$key])) {
            return;
        }

        if (!$status->isFinal() && $this->events[$key]['status']->isFinal()) {
            return;
        }

        $this->events[$key]['status'] = $status;
        $this->events[$key]['status_reason'] = $reason;
        $this->events[$key]['processed_at'] = $status->isFinal() ? new DateTimeImmutable() : null;
        if ($paymentReference !== null && $paymentReference !== '') {
            $this->events[$key]['payment_reference'] = $paymentReference;
        }
    }

    public function prune(DateTimeInterface $before): int
    {
        $deleted = 0;
        foreach ($this->events as $key => $row) {
            if ($row['status']->isFinal() && $row['created_at']->getTimestamp() < $before->getTimestamp()) {
                unset($this->events[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function key(string $gateway, string $eventId): string
    {
        return $gateway . ':' . $eventId;
    }
}
