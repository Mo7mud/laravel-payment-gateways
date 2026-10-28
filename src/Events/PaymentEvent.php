<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Base for package events. Events carry scalars only (serialisable for queued
 * listeners) and are dispatched after the database transaction commits.
 */
abstract class PaymentEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $paymentReference,
        public readonly string $gateway,
    ) {}
}
