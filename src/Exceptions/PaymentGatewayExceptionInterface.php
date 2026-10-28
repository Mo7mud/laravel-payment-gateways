<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

use Throwable;

/**
 * Marker implemented by every exception the package throws, so host
 * applications can catch all package errors with a single type.
 */
interface PaymentGatewayExceptionInterface extends Throwable {}
