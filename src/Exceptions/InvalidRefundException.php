<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

use InvalidArgumentException;

/** A refund request that violates a business rule (amount, state, currency). */
final class InvalidRefundException extends InvalidArgumentException implements PaymentGatewayExceptionInterface {}
