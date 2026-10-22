<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Support\PackageConfig;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

/**
 * One refund, void or reversal against a payment.
 *
 * @property string $uuid
 * @property string $payment_reference
 * @property string $gateway
 * @property RefundStatus $status
 * @property string $type
 * @property string $source api|webhook|reconciliation
 * @property int $amount_minor
 * @property string $currency
 * @property int $currency_scale
 * @property string|null $gateway_refund_reference
 * @property string|null $idempotency_key
 * @property string|null $reason
 * @property string|null $last_error
 * @property bool|null $outcome_unknown
 */
final class PaymentRefund extends Model
{
    public const SOURCE_API = 'api';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_RECONCILIATION = 'reconciliation';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => RefundStatus::class,
        'metadata' => 'array',
        'amount_minor' => 'integer',
        'currency_scale' => 'integer',
        'outcome_unknown' => 'boolean',
        'completed_at' => 'immutable_datetime',
    ];

    public function getTable(): string
    {
        return PackageConfig::table('refunds');
    }

    public function amountMoney(): Money
    {
        return Money::fromMinorUnits(
            (int) $this->amount_minor,
            Currency::fromCode((string) $this->currency, (int) $this->currency_scale),
        );
    }
}
