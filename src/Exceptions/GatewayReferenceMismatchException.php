<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

/** The gateway returned data that belongs to a different merchant order. */
final class GatewayReferenceMismatchException extends PaymentGatewayException
{
    public function __construct(string $expected, string $received)
    {
        parent::__construct(sprintf(
            'Gateway data belongs to merchant order [%s], expected [%s].',
            $received,
            $expected,
        ));
    }
}
