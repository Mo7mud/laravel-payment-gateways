<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final readonly class PaymentStatusResult
{
    /**
     * @param PaymentStatus|null $status null means the gateway reported a status the
     *        package does not recognise; the stored status is left unchanged.
     * @param string|null $merchantOrderReference The merchant order id the gateway data
     *        belongs to, when the gateway reports it. Used to reject mismatched data.
     */
    public function __construct(
        public string $paymentReference,
        public ?PaymentStatus $status,
        public ?Money $amount = null,
        public ?Money $paidAmount = null,
        public ?Money $refundedAmount = null,
        public ?string $gatewayReference = null,
        public ?string $gatewayTransactionId = null,
        public ?string $gatewayStatus = null,
        public ?DateTimeImmutable $occurredAt = null,
        public array $metadata = [],
        public ?string $merchantOrderReference = null,
    ) {
    }
}
