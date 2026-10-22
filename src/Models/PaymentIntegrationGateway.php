<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentIntegrationGateway extends Model
{
    protected $table = 'payment_integration_gateways';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(PaymentIntegrationPackage::class, 'package_id');
    }
}
