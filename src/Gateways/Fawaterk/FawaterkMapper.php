<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentCreationResult;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundNotification;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookResult;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventKind;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final class FawaterkMapper
{
    public function creationResult(CreatePaymentRequest $request, array $response): PaymentCreationResult
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $intentKey = isset($data['intent_key']) ? (string) $data['intent_key'] : null;
        $paymentData = is_array($data['payment_data'] ?? null) ? $data['payment_data'] : [];

        $checkoutUrl = isset($data['url']) ? (string) $data['url'] : null;
        $directType = null;

        if ($checkoutUrl === null && isset($paymentData['redirectTo'])) {
            $checkoutUrl = (string) $paymentData['redirectTo'];
            $directType = 'redirect';
        } elseif (isset($paymentData['referenceNumber'])) {
            $directType = 'reference';
        } elseif (isset($paymentData['systemReference']) || isset($paymentData['isoQr'])) {
            $directType = 'wallet';
        }

        $metadata = array_filter([
            'fawaterk_intent_key' => $intentKey,
            'fawaterk_payment_data' => $paymentData !== [] ? $paymentData : null,
            'fawaterk_payment_data_type' => $directType,
            'fawaterk_reference_number' => isset($paymentData['referenceNumber']) ? (string) $paymentData['referenceNumber'] : null,
            'fawaterk_reference_expire_date' => $paymentData['expireDate'] ?? ($paymentData['expirationTime'] ?? null),
            'fawaterk_system_reference' => isset($paymentData['systemReference']) ? (string) $paymentData['systemReference'] : null,
            'fawaterk_iso_qr' => isset($paymentData['isoQr']) ? (string) $paymentData['isoQr'] : null,
            'fawaterk_redirect_to' => isset($paymentData['redirectTo']) ? (string) $paymentData['redirectTo'] : null,
            'fawaterk_create_response_status' => isset($response['status']) ? (string) $response['status'] : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $expiresAt = null;
        if (isset($data['expires_in']) && is_numeric($data['expires_in'])) {
            $expiresAt = new DateTimeImmutable('now +' . max(0, (int) $data['expires_in']) . ' seconds');
        }

        return new PaymentCreationResult(
            paymentReference: $request->paymentReference,
            status: PaymentStatus::PENDING,
            checkoutUrl: $checkoutUrl,
            gatewayReference: $intentKey,
            gatewayTransactionId: null,
            gatewayStatus: isset($response['status']) ? (string) $response['status'] : null,
            expiresAt: $expiresAt,
            amount: $request->amount,
            metadata: $metadata,
        );
    }

    /**
     * @param Money|null $expectedAmount The stored payment amount. Fawaterk reports
     *        `total` converted to EGP, so it is only compared for EGP payments.
     */
    public function statusResult(string $paymentReference, array $response, ?Money $expectedAmount = null): PaymentStatusResult
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $currency = Currency::fromGatewayCode((string) ($data['currency'] ?? 'EGP'));
        $statusText = strtolower(trim((string) ($data['status_text'] ?? '')));
        $paid = $data['paid'] ?? null;
        $isPaid = $paid === true || $paid === 1 || $paid === '1';

        $totalEgp = $this->money($data['total'] ?? null, Currency::egp());
        $comparable = $expectedAmount === null || $expectedAmount->currency->code === 'EGP';
        $amount = $comparable ? $totalEgp : null;

        $payLoad = $this->decodePayLoad($data['pay_load'] ?? null);
        $merchantReference = $payLoad['payment_reference'] ?? $payLoad['paymentReference'] ?? null;

        return new PaymentStatusResult(
            paymentReference: $paymentReference,
            status: $this->transactionStatus($statusText, $isPaid),
            amount: $amount,
            paidAmount: $isPaid ? $amount : null,
            refundedAmount: null,
            gatewayReference: $this->nullable($data['intent_key'] ?? null),
            gatewayTransactionId: $this->transactionId($data['transaction_id'] ?? null),
            gatewayStatus: $statusText !== '' ? $statusText : null,
            occurredAt: $this->parseDate($data['paid_at'] ?? null),
            metadata: [
                'fawaterk_intent_key' => $this->nullable($data['intent_key'] ?? null),
                'fawaterk_transaction_id' => $this->transactionId($data['transaction_id'] ?? null),
                'fawaterk_payment_method' => $this->nullable($data['payment_method'] ?? null),
                'fawaterk_currency' => $currency->code,
                'fawaterk_total_egp' => $totalEgp?->toDecimal(),
                'fawaterk_due_date' => $this->nullable($data['due_date'] ?? null),
            ],
            merchantOrderReference: is_scalar($merchantReference) && (string) $merchantReference !== '' ? (string) $merchantReference : null,
        );
    }

    /**
     * Only the fields covered by each signature are trusted:
     *  - paid/pending: TransactionId, TransactionKey, PaymentMethod (status and
     *    amounts are NOT signed, so the claim is verified with getTransactionData);
     *  - failed: same string as paid, so a failed notification can be replayed as
     *    "paid" — harmless because of the verification above;
     *  - cancel: referenceId, PaymentMethod;
     *  - refund: transactionId, amount, currency.
     */
    public function webhookResult(string $eventType, array $payload): WebhookResult
    {
        $paymentReference = $this->paymentReferenceFromPayload($payload);
        $gatewayReference = null;
        $gatewayTransactionId = null;
        $gatewayStatus = null;
        $status = null;
        $amount = null;
        $occurredAt = null;
        $kind = WebhookEventKind::PAYMENT;
        $refund = null;
        $requiresVerification = false;
        $failureReason = null;

        switch ($eventType) {
            case 'paid':
            case 'pending':
                $gatewayReference = $this->nullable($payload['transaction_key'] ?? null);
                $gatewayTransactionId = $this->transactionId($payload['transaction_id'] ?? null);
                $gatewayStatus = $this->nullable($payload['status'] ?? null);
                $status = strtolower((string) ($payload['status'] ?? 'pending')) === 'paid'
                    ? PaymentStatus::SUCCEEDED
                    : PaymentStatus::PENDING;
                $amount = $this->money($payload['paidAmount'] ?? null, $this->currency($payload['paidCurrency'] ?? null));
                $occurredAt = $this->parseDate($payload['paidAt'] ?? null);
                $requiresVerification = true;
                break;

            case 'failed':
                $kind = WebhookEventKind::ATTEMPT_FAILED;
                $gatewayReference = $this->nullable($payload['transaction_key'] ?? null);
                $gatewayTransactionId = $this->transactionId($payload['transaction_id'] ?? null);
                $gatewayStatus = 'failed';
                $failureReason = $this->nullable($payload['errorMessage'] ?? null);
                break;

            case 'cancelled':
                // The expired reference only; the invoice itself may still be paid another way.
                $kind = WebhookEventKind::ATTEMPT_FAILED;
                $gatewayReference = $this->nullable($payload['transactionKey'] ?? null);
                $gatewayTransactionId = $this->transactionId($payload['transactionId'] ?? null);
                $gatewayStatus = strtoupper((string) ($payload['status'] ?? 'CANCELED'));
                $failureReason = 'payment reference expired or cancelled';
                break;

            case 'refund':
                $kind = WebhookEventKind::REFUND;
                $gatewayTransactionId = $this->transactionId($payload['transactionId'] ?? null);
                $gatewayStatus = $this->nullable($payload['status'] ?? null);
                $currency = $this->currency($payload['currency'] ?? null);
                $amount = $this->money($payload['amount'] ?? null, $currency);
                $occurredAt = $this->parseDate($payload['approvedAt'] ?? null);
                if ($amount === null) {
                    throw new WebhookVerificationException('Fawaterk refund webhook has no valid amount.');
                }
                $refund = new RefundNotification($amount, $this->refundStatus($payload['status'] ?? null));
                // No refund id, and status/approvedAt are unsigned: treat the
                // notification as a trigger and confirm with the refund list API.
                $requiresVerification = true;
                break;
        }

        $metadata = [
            'event' => $eventType,
            'fawaterk_payment_method' => $this->nullable($payload['payment_method'] ?? ($payload['paymentMethod'] ?? null)),
            'fawaterk_reference_id' => $this->nullable($payload['referenceId'] ?? null),
            'failure_reason' => $failureReason !== null ? mb_substr($failureReason, 0, 200) : null,
        ];

        return new WebhookResult(
            eventId: 'fawaterk:' . $this->eventId($eventType, $payload),
            eventType: $eventType,
            paymentReference: $paymentReference,
            status: $status,
            amount: $amount,
            gatewayReference: $gatewayReference,
            gatewayTransactionId: $gatewayTransactionId,
            gatewayStatus: $gatewayStatus,
            occurredAt: $occurredAt,
            metadata: array_filter($metadata, static fn ($value): bool => $value !== null && $value !== ''),
            rawPayload: $payload,
            kind: $kind,
            refund: $refund,
            requiresVerification: $requiresVerification,
            // pay_load is not covered by any Fawaterk signature.
            referenceTrusted: false,
        );
    }

    public function mapStatus(string $statusText, mixed $paid = null): PaymentStatus
    {
        if ($paid === true || $paid === 1 || $paid === '1') {
            return PaymentStatus::SUCCEEDED;
        }

        return match (strtolower(trim($statusText))) {
            'paid', 'success', 'succeeded', 'completed' => PaymentStatus::SUCCEEDED,
            'failed', 'failure', 'declined', 'error' => PaymentStatus::FAILED,
            'cancelled', 'canceled', 'voided' => PaymentStatus::CANCELLED,
            'expired' => PaymentStatus::EXPIRED,
            'refunding' => PaymentStatus::REFUNDING,
            'partially_refunded' => PaymentStatus::PARTIALLY_REFUNDED,
            'refunded' => PaymentStatus::REFUNDED,
            default => PaymentStatus::PENDING,
        };
    }

    /**
     * Status of the invoice as a whole. A failed attempt is not final (the
     * customer may pay with another method), so "failed" maps to null.
     */
    private function transactionStatus(string $statusText, bool $isPaid): ?PaymentStatus
    {
        if ($isPaid) {
            return PaymentStatus::SUCCEEDED;
        }

        return match ($statusText) {
            'paid', 'success', 'succeeded', 'completed' => PaymentStatus::SUCCEEDED,
            'pending', 'unpaid', 'new', 'processing', '' => PaymentStatus::PENDING,
            'expired' => PaymentStatus::EXPIRED,
            'cancelled', 'canceled', 'voided' => PaymentStatus::CANCELLED,
            'refunded' => PaymentStatus::REFUNDED,
            'partially_refunded' => PaymentStatus::PARTIALLY_REFUNDED,
            default => null,
        };
    }

    private function refundStatus(mixed $status): RefundStatus
    {
        $normalized = strtolower(trim((string) (is_scalar($status) ? $status : '')));

        return match ($normalized) {
            '1', 'true', 'approved', 'success', 'succeeded', 'completed', 'refunded' => RefundStatus::SUCCEEDED,
            '0', 'false', 'rejected', 'declined', 'failed', 'failure' => RefundStatus::FAILED,
            default => RefundStatus::PENDING,
        };
    }

    private function money(mixed $value, ?Currency $currency): ?Money
    {
        if ($currency === null || $value === null || !is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }

        try {
            return Money::fromGatewayAmount(is_string($value) ? $value : (is_int($value) ? $value : (float) $value), $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function currency(mixed $code): ?Currency
    {
        if (!is_string($code) || trim($code) === '') {
            return null;
        }

        try {
            return Currency::fromGatewayCode($code);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function transactionId(mixed $value): ?string
    {
        $value = $this->nullable($value);

        return $value === null || $value === '0' ? null : $value;
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value) || (string) $value === '') {
            return null;
        }

        return (string) $value;
    }

    public function decodePayLoad(mixed $payLoad): array
    {
        if (is_array($payLoad)) {
            return $payLoad;
        }

        if (!is_string($payLoad) || trim($payLoad) === '') {
            return [];
        }

        try {
            $decoded = json_decode($payLoad, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function paymentReferenceFromPayload(array $payload): string
    {
        $payLoad = $this->decodePayLoad($payload['pay_load'] ?? null);
        if (isset($payLoad['payment_reference']) && (string) $payLoad['payment_reference'] !== '') {
            return (string) $payLoad['payment_reference'];
        }

        if (isset($payLoad['paymentReference']) && (string) $payLoad['paymentReference'] !== '') {
            return (string) $payLoad['paymentReference'];
        }

        return '';
    }

    private function eventId(string $eventType, array $payload): string
    {
        $natural = match ($eventType) {
            'paid', 'pending', 'failed' => implode('|', [
                $eventType,
                (string) ($payload['transaction_id'] ?? ''),
                (string) ($payload['transaction_key'] ?? ''),
                (string) ($payload['payment_method'] ?? ''),
                (string) ($payload['status'] ?? ''),
            ]),
            'cancelled' => implode('|', [
                $eventType,
                (string) ($payload['referenceId'] ?? ''),
                (string) ($payload['transactionId'] ?? ''),
                (string) ($payload['transactionKey'] ?? ''),
                (string) ($payload['status'] ?? ''),
            ]),
            'refund' => implode('|', [
                $eventType,
                (string) ($payload['transactionId'] ?? ''),
                (string) ($payload['amount'] ?? ''),
                (string) ($payload['currency'] ?? ''),
                (string) ($payload['approvedAt'] ?? ''),
                (string) ($payload['status'] ?? ''),
            ]),
            default => json_encode($payload, JSON_THROW_ON_ERROR),
        };

        return hash('sha256', $natural);
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || (string) $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
