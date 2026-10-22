<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Exceptions;

/**
 * An HTTP-level failure talking to a gateway. The response body is kept for
 * debugging but is never included in the message.
 */
abstract class GatewayApiException extends PaymentGatewayException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $response = [],
    ) {
        parent::__construct($message, $statusCode);
    }

    /** Connection failures, timeouts, 408, 429 and 5xx are worth retrying. */
    public function isTransient(): bool
    {
        return $this->statusCode === 0
            || $this->statusCode === 408
            || $this->statusCode === 429
            || $this->statusCode >= 500;
    }
}
