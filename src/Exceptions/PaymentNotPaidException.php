<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;

/** The operation needs a paid payment (PaymentStatus::isPaid()). */
final class PaymentNotPaidException extends PaymentGatewayException
{
    public function __construct(
        public readonly string $reference,
        public readonly PaymentStatus $status,
    ) {
        parent::__construct(sprintf('Payment [%s] is not paid (status [%s]).', $reference, $status->value));
    }
}
