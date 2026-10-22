<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Enums;

enum PaymentStatus: string
{
    case INITIATING = 'initiating';
    case CREATION_FAILED = 'creation_failed';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case REFUNDING = 'refunding';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case REFUNDED = 'refunded';

    /** Money has been collected (possibly partially returned since). */
    public function isPaid(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::REFUNDING, self::PARTIALLY_REFUNDED, self::REFUNDED], true);
    }

    /** No money has been collected yet. */
    public function isUnpaid(): bool
    {
        return !$this->isPaid();
    }

    /** Paid and not fully refunded: the order still has to be fulfilled. */
    public function awaitsFulfilment(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::REFUNDING, self::PARTIALLY_REFUNDED], true);
    }

    public function isRefundable(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::PARTIALLY_REFUNDED], true);
    }

    /** Closed without payment; a later confirmed capture is a late payment. */
    public function isClosedUnpaid(): bool
    {
        return in_array($this, [self::FAILED, self::CANCELLED, self::EXPIRED], true);
    }

    /**
     * Progress rank used to detect stale (out-of-order) updates.
     * A lower-ranked update never overrides a higher-ranked state.
     */
    public function rank(): int
    {
        return match ($this) {
            self::INITIATING, self::CREATION_FAILED => 0,
            self::PENDING => 1,
            self::PROCESSING => 2,
            self::FAILED, self::CANCELLED, self::EXPIRED => 3,
            self::SUCCEEDED => 4,
            self::REFUNDING, self::PARTIALLY_REFUNDED => 5,
            self::REFUNDED => 6,
        };
    }
}
