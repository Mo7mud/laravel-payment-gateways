<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;

final class InvalidPaymentStateTransitionException extends PaymentGatewayException
{
    public function __construct(
        public readonly PaymentStatus $from,
        public readonly PaymentStatus $to,
    ) {
        parent::__construct(sprintf(
            'Invalid payment state transition from [%s] to [%s].',
            $from->value,
            $to->value,
        ));
    }
}
