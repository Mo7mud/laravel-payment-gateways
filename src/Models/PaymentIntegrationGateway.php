<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $package_id
 * @property string $gateway
 * @property string $display_name
 * @property int $sort_order
 * @property bool $enabled
 */
final class PaymentIntegrationGateway extends Model
{
    protected $table = 'payment_integration_gateways';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /** @return BelongsTo<PaymentIntegrationPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PaymentIntegrationPackage::class, 'package_id');
    }
}
