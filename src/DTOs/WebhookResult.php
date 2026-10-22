<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventKind;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final readonly class WebhookResult
{
    /**
     * @param PaymentStatus|null $status Claimed payment status (PAYMENT kind), or the
     *        resulting stored status in results returned by PaymentService.
     * @param bool $requiresVerification When true, PaymentService confirms the state
     *        with the gateway API before changing anything.
     * @param bool $referenceTrusted Whether $paymentReference is covered by the
     *        gateway signature and may be used to locate the payment.
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $paymentReference,
        public ?PaymentStatus $status,
        public ?Money $amount = null,
        public ?string $gatewayReference = null,
        public ?string $gatewayTransactionId = null,
        public ?string $gatewayStatus = null,
        public ?DateTimeImmutable $occurredAt = null,
        public array $metadata = [],
        public array $rawPayload = [],
        public bool $duplicate = false,
        public WebhookEventKind $kind = WebhookEventKind::PAYMENT,
        public ?RefundNotification $refund = null,
        public bool $requiresVerification = false,
        public bool $referenceTrusted = true,
        public ?string $ignoreReason = null,
        public ?string $processingNote = null,
    ) {
    }

    public function asDuplicate(): self
    {
        return $this->copy(duplicate: true);
    }

    public function withOutcome(string $paymentReference, ?PaymentStatus $status, ?string $note): self
    {
        return $this->copy(paymentReference: $paymentReference, status: $status, processingNote: $note, statusGiven: true);
    }

    private function copy(
        ?string $paymentReference = null,
        ?PaymentStatus $status = null,
        ?bool $duplicate = null,
        ?string $processingNote = null,
        bool $statusGiven = false,
    ): self {
        return new self(
            eventId: $this->eventId,
            eventType: $this->eventType,
            paymentReference: $paymentReference ?? $this->paymentReference,
            status: $statusGiven ? $status : $this->status,
            amount: $this->amount,
            gatewayReference: $this->gatewayReference,
            gatewayTransactionId: $this->gatewayTransactionId,
            gatewayStatus: $this->gatewayStatus,
            occurredAt: $this->occurredAt,
            metadata: $this->metadata,
            rawPayload: $this->rawPayload,
            duplicate: $duplicate ?? $this->duplicate,
            kind: $this->kind,
            refund: $this->refund,
            requiresVerification: $this->requiresVerification,
            referenceTrusted: $this->referenceTrusted,
            ignoreReason: $this->ignoreReason,
            processingNote: $processingNote ?? $this->processingNote,
        );
    }
}
