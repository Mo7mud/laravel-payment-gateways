<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $package
 * @property string $display_name
 * @property bool $enabled
 */
final class PaymentIntegrationPackage extends Model
{
    protected $table = 'payment_integration_packages';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /** @return HasMany<PaymentIntegrationGateway, $this> */
    public function gateways(): HasMany
    {
        return $this->hasMany(PaymentIntegrationGateway::class, 'package_id');
    }
}
