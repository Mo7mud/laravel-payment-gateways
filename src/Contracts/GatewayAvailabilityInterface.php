<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

/** Decides whether new payments may be created on a gateway. */
interface GatewayAvailabilityInterface
{
    public function isEnabled(string $gateway): bool;
}
