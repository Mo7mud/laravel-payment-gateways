<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Mo7mud\LaravelPaymentGateway\Enums\GatewayName;
use Mo7mud\LaravelPaymentGateway\ValueObjects\GatewayCapabilities;

interface PaymentGatewayInterface
{
    /** Built-in gateways return a GatewayName; third-party gateways may return a string key. */
    public function name(): GatewayName|string;

    public function capabilities(): GatewayCapabilities;
}
