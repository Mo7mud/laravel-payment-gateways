<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

/** Another request is creating the gateway session for this reference right now. */
final class PaymentCreationInProgressException extends PaymentGatewayException
{
    public function __construct(string $reference)
    {
        parent::__construct(sprintf('Payment [%s] is already being created. Retry shortly.', $reference));
    }
}
