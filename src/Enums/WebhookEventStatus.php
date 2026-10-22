<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Enums;

enum WebhookEventStatus: string
{
    case RECEIVED = 'received';
    case PROCESSED = 'processed';
    case IGNORED = 'ignored';
    case FAILED = 'failed';

    public function isFinal(): bool
    {
        return $this === self::PROCESSED || $this === self::IGNORED;
    }
}
