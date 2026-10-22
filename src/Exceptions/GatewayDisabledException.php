<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

final class GatewayDisabledException extends PaymentGatewayException
{
    public function __construct(string $gateway)
    {
        parent::__construct(sprintf('Payment gateway [%s] is disabled.', $gateway));
    }
}
