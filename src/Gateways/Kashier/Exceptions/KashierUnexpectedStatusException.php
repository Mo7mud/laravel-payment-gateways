<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions;

use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentGatewayException;

final class KashierUnexpectedStatusException extends PaymentGatewayException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(sprintf('Unsupported Kashier payment status [%s].', $status));
    }
}
