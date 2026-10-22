<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

use RuntimeException;

abstract class PaymentGatewayException extends RuntimeException implements PaymentGatewayExceptionInterface
{
}
