<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PaymentIntegrationPackage extends Model
{
    protected $table = 'payment_integration_packages';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function gateways(): HasMany
    {
        return $this->hasMany(PaymentIntegrationGateway::class, 'package_id');
    }
}
