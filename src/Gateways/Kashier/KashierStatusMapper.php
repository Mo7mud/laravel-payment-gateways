<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier;

use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierUnexpectedStatusException;

final class KashierStatusMapper
{
    /** Returns null for statuses the package does not know; callers leave the payment unchanged. */
    public function tryMap(string $status): ?PaymentStatus
    {
        return match (strtoupper(trim($status))) {
            'CREATED', 'OPENED', 'PENDING', 'INITIATED', 'NEW' => PaymentStatus::PENDING,
            'PROCESSING', 'AUTHENTICATION_IN_PROGRESS', 'AUTHORIZED' => PaymentStatus::PROCESSING,
            'SUCCESS', 'SUCCEEDED', 'CAPTURED', 'APPROVED', 'PAID' => PaymentStatus::SUCCEEDED,
            'FAILED', 'FAILURE', 'REFUSED', 'DECLINED', 'ERROR', 'REJECTED' => PaymentStatus::FAILED,
            'EXPIRED' => PaymentStatus::EXPIRED,
            // Session closed by PATCH /v3/payment/sessions/{id} (documented).
            'ABANDONED' => PaymentStatus::CANCELLED,
            'CANCELLED', 'CANCELED', 'VOIDED', 'REVERSED' => PaymentStatus::CANCELLED,
            'REFUNDING' => PaymentStatus::REFUNDING,
            'PARTIALLY_REFUNDED' => PaymentStatus::PARTIALLY_REFUNDED,
            'REFUNDED' => PaymentStatus::REFUNDED,
            default => null,
        };
    }

    public function map(string $status): PaymentStatus
    {
        return $this->tryMap($status) ?? throw new KashierUnexpectedStatusException($status);
    }
}
