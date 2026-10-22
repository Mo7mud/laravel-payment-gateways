<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

/**
 * Gateways list metadata keys that carry their own identifiers. Callers may
 * not override these on refunds; the values always come from the stored payment.
 */
interface DeclaresReservedMetadataKeys
{
    /** @return list<string> */
    public function reservedMetadataKeys(): array;
}
