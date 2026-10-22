<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

final class UnsupportedPaymentOperationException extends PaymentGatewayException
{
    public function __construct(string $gateway, string $operation)
    {
        parent::__construct(sprintf(
            'Gateway [%s] does not support operation [%s].',
            $gateway,
            $operation,
        ));
    }
}
