<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

/** The payment reference already exists with a different gateway, amount or currency. */
final class PaymentReferenceConflictException extends PaymentGatewayException
{
    public function __construct(string $reference, string $reason)
    {
        parent::__construct(sprintf('Payment reference [%s] is already used: %s.', $reference, $reason));
    }
}
