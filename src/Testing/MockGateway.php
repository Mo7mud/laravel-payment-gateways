<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Testing;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\Contracts\DeclaresReservedMetadataKeys;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentCreatorInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentGatewayInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentRefundInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReconciliationInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReturnHandlerInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentStatusProviderInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\WebhookHandlerInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentCreationResult;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnResult;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundResult;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookResult;
use Mo7mud\LaravelPaymentGateway\Enums\GatewayName;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\ValueObjects\GatewayCapabilities;

/**
 * In-memory test double (like Http::fake()). Not registered by default; add it
 * to payment-gateway.gateways only in tests.
 */
class MockGateway implements
    DeclaresReservedMetadataKeys,
    PaymentGatewayInterface,
    PaymentCreatorInterface,
    PaymentStatusProviderInterface,
    PaymentRefundInterface,
    PaymentReconciliationInterface,
    PaymentReturnHandlerInterface,
    WebhookHandlerInterface
{
    /** @var array<string, PaymentStatus> */
    private array $statuses = [];

    public function name(): GatewayName
    {
        return GatewayName::MOCK;
    }

    public function reservedMetadataKeys(): array
    {
        return ['mock_reference'];
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            refunds: true,
            partialRefunds: true,
            statusQuery: true,
            reconciliation: true,
            webhooks: true,
            hostedCheckout: true,
            returnHandling: true,
        );
    }

    public function createPayment(CreatePaymentRequest $request): PaymentCreationResult
    {
        $this->statuses[$request->paymentReference] = PaymentStatus::PENDING;

        return new PaymentCreationResult(
            paymentReference: $request->paymentReference,
            status: PaymentStatus::PENDING,
            checkoutUrl: 'https://mock.test/checkout/'.$request->paymentReference,
            gatewayReference: 'mock-'.$request->paymentReference,
            gatewayTransactionId: 'mock-tx-'.$request->paymentReference,
            gatewayStatus: 'PENDING',
            amount: $request->amount,
            metadata: ['mock_order' => $request->merchantOrderReference()],
        );
    }

    public function getPaymentStatus(PaymentStatusRequest $request): PaymentStatusResult
    {
        $status = $this->statuses[$request->paymentReference] ?? PaymentStatus::PENDING;

        return new PaymentStatusResult(
            paymentReference: $request->paymentReference,
            status: $status,
            gatewayReference: $request->gatewayReference ?? 'mock-'.$request->paymentReference,
            gatewayTransactionId: $request->gatewayTransactionId ?? 'mock-tx-'.$request->paymentReference,
            gatewayStatus: strtoupper($status->value),
            occurredAt: new DateTimeImmutable(),
        );
    }

    public function reconcilePayment(PaymentStatusRequest $request): PaymentStatusResult
    {
        return $this->getPaymentStatus($request);
    }

    public function refund(RefundPaymentRequest $request): RefundResult
    {
        $this->statuses[$request->paymentReference] = $request->amount === null
            ? PaymentStatus::REFUNDED
            : PaymentStatus::PARTIALLY_REFUNDED;

        return new RefundResult(
            paymentReference: $request->paymentReference,
            status: RefundStatus::SUCCEEDED,
            amount: $request->amount,
            refundReference: 'mock-refund-'.$request->paymentReference,
            gatewayStatus: 'SUCCEEDED',
            occurredAt: new DateTimeImmutable(),
            metadata: $request->metadata,
        );
    }

    /** Trusts the query (payment_reference, status): a test double has no signature. */
    public function handleReturn(PaymentReturnRequest $request): PaymentReturnResult
    {
        return new PaymentReturnResult(
            paymentReference: (string) ($request->query['payment_reference'] ?? ''),
            observedStatus: PaymentStatus::tryFrom((string) ($request->query['status'] ?? '')) ?? PaymentStatus::PENDING,
            rawQuery: $request->query,
            signatureVerified: true,
        );
    }

    public function handleWebhook(WebhookRequest $request): WebhookResult
    {
        $paymentReference = (string) ($request->payload['payment_reference'] ?? '');
        $status = PaymentStatus::from((string) ($request->payload['status'] ?? PaymentStatus::PENDING->value));
        $this->statuses[$paymentReference] = $status;

        return new WebhookResult(
            eventId: (string) ($request->payload['event_id'] ?? hash('sha256', json_encode($request->payload, JSON_THROW_ON_ERROR))),
            eventType: (string) ($request->payload['event_type'] ?? 'payment.updated'),
            paymentReference: $paymentReference,
            status: $status,
            gatewayReference: $request->payload['gateway_reference'] ?? null,
            gatewayTransactionId: $request->payload['gateway_transaction_id'] ?? null,
            gatewayStatus: $request->payload['gateway_status'] ?? null,
            occurredAt: new DateTimeImmutable(),
            metadata: $request->payload['metadata'] ?? [],
            rawPayload: $request->payload,
        );
    }

    public function setStatus(string $paymentReference, PaymentStatus $status): void
    {
        $this->statuses[$paymentReference] = $status;
    }
}
