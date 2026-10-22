<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final class PaymentAmountMismatchException extends PaymentGatewayException
{
    public function __construct(
        public readonly Money $expected,
        public readonly Money $received,
    ) {
        parent::__construct(sprintf(
            'Payment amount mismatch. Expected [%s %s], received [%s %s].',
            $expected->toDecimal(),
            $expected->currency->code,
            $received->toDecimal(),
            $received->currency->code,
        ));
    }
}
