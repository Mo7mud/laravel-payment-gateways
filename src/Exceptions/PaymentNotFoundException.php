<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

final class PaymentNotFoundException extends PaymentGatewayException
{
    public function __construct(string $reference)
    {
        parent::__construct(sprintf('Payment [%s] was not found.', $reference));
    }
}
