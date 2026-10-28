<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

/** A refund, void or reversal outcome reported by a gateway webhook. */
final readonly class RefundNotification
{
    public const TYPE_REFUND = 'refund';

    public const TYPE_VOID = 'void';

    public const TYPE_REVERSAL = 'reversal';

    public function __construct(
        public Money $amount,
        public RefundStatus $status,
        public ?string $refundReference = null,
        public string $type = self::TYPE_REFUND,
        public ?Money $gatewayTotalRefunded = null,
    ) {}

    public function isCancellation(): bool
    {
        return $this->type === self::TYPE_VOID || $this->type === self::TYPE_REVERSAL;
    }
}
