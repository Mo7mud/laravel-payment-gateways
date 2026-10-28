<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Support\PackageConfig;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

/**
 * @property string $reference
 * @property string $gateway
 * @property PaymentStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property int $currency_scale
 * @property string|null $gateway_reference
 * @property string|null $gateway_transaction_id
 * @property string|null $gateway_order_reference
 * @property string|null $gateway_status
 * @property int|null $attempt
 * @property int|null $refunded_amount_minor
 * @property bool|null $late_payment
 * @property string|null $last_error
 * @property int|null $reconcile_attempts
 * @property \DateTimeImmutable|null $fulfilled_at
 * @property int|null $fulfilment_dispatches
 * @property \DateTimeImmutable|null $next_fulfilment_dispatch_at
 * @property array<mixed>|null $metadata
 * @property string|null $checkout_url
 * @property \DateTimeImmutable|null $paid_at
 * @property \DateTimeImmutable|null $refunded_at
 * @property \DateTimeImmutable|null $expires_at
 * @property \DateTimeImmutable|null $next_reconcile_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
final class Payment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'status' => PaymentStatus::class,
        'metadata' => 'array',
        'paid_at' => 'immutable_datetime',
        'refunded_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'next_reconcile_at' => 'immutable_datetime',
        'fulfilled_at' => 'immutable_datetime',
        'next_fulfilment_dispatch_at' => 'immutable_datetime',
        'fulfilment_dispatches' => 'integer',
        'late_payment' => 'boolean',
        'amount_minor' => 'integer',
        'refunded_amount_minor' => 'integer',
        'currency_scale' => 'integer',
        'attempt' => 'integer',
        'reconcile_attempts' => 'integer',
    ];

    public function getTable(): string
    {
        return PackageConfig::table('payments');
    }

    public function amountMoney(): Money
    {
        return Money::fromMinorUnits(
            (int) $this->amount_minor,
            Currency::fromCode((string) $this->currency, (int) $this->currency_scale),
        );
    }

    public function setAmountMoney(Money $money): static
    {
        $this->amount_minor = $money->minorAmount;
        $this->currency = $money->currency->code;
        $this->currency_scale = $money->currency->fractionDigits;

        return $this;
    }

    public function refundedMoney(): Money
    {
        return Money::fromMinorUnits((int) ($this->refunded_amount_minor ?? 0), $this->amountMoney()->currency);
    }

    public function gatewayKey(): string
    {
        return (string) $this->gateway;
    }

    public function merchantOrderReference(): string
    {
        $value = (string) ($this->gateway_order_reference ?? '');

        return $value !== '' ? $value : (string) $this->reference;
    }
}
