<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventStatus;
use Mo7mud\LaravelPaymentGateway\Support\PackageConfig;

final class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'status' => WebhookEventStatus::class,
        'processed_at' => 'immutable_datetime',
        'attempts' => 'integer',
    ];

    public function getTable(): string
    {
        return PackageConfig::table('webhook_events');
    }
}
