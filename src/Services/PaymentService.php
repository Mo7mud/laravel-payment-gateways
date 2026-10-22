<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Services;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Mo7mud\LaravelPaymentGateway\Contracts\DeclaresReservedMetadataKeys;
use Mo7mud\LaravelPaymentGateway\Contracts\GatewayAvailabilityInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentCreatorInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentGatewayInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReconciliationInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentRefundInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReturnHandlerInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentStatusProviderInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\RefundListingInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\TransactionRunnerInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\WebhookHandlerInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\GatewayRefundList;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentCreationResult;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnResult;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundNotification;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundResult;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookResult;
use Mo7mud\LaravelPaymentGateway\Enums\GatewayName;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Enums\TransitionDecision;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventKind;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventStatus;
use Mo7mud\LaravelPaymentGateway\Events\LatePaymentReceived;
use Mo7mud\LaravelPaymentGateway\Events\PaymentAttemptFailed;
use Mo7mud\LaravelPaymentGateway\Events\PaymentClosed;
use Mo7mud\LaravelPaymentGateway\Events\PaymentEvent;
use Mo7mud\LaravelPaymentGateway\Events\PaymentRefunded;
use Mo7mud\LaravelPaymentGateway\Events\PaymentStatusChanged;
use Mo7mud\LaravelPaymentGateway\Events\PaymentSucceeded;
use Mo7mud\LaravelPaymentGateway\Exceptions\GatewayDisabledException;
use Mo7mud\LaravelPaymentGateway\Exceptions\GatewayReferenceMismatchException;
use Mo7mud\LaravelPaymentGateway\Exceptions\InvalidRefundException;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentAmountMismatchException;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentCreationInProgressException;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentNotFoundException;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentNotPaidException;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentReferenceConflictException;
use Mo7mud\LaravelPaymentGateway\Exceptions\UnsupportedPaymentOperationException;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Models\Payment;
use Mo7mud\LaravelPaymentGateway\Models\PaymentRefund;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\PaymentRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Repositories\RefundRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Repositories\WebhookEventRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Support\PayloadRedactor;
use Mo7mud\LaravelPaymentGateway\Support\Uuid;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Throwable;

/**
 * The single place that changes payment state.
 *
 * Rules enforced here:
 *  - every state change happens inside a transaction with the payment row locked;
 *  - out-of-order updates are ignored (never thrown), a capture after closing is a late payment;
 *  - webhooks that ask for it are confirmed with the gateway API before anything changes;
 *  - refunds live in a ledger; the refunded total is the sum of confirmed ledger rows.
 */
final class PaymentService
{
    /** Identifiers that always come from the stored payment. */
    private const GENERIC_RESERVED_KEYS = ['gateway_reference', 'gateway_transaction_id', 'gateway_order_reference'];

    private readonly RefundRepositoryInterface $refunds;
    private readonly LoggerInterface $logger;
    private readonly PayloadRedactor $redactor;
    private readonly array $options;

    /** @var list<object> events raised inside the current transaction */
    private array $outbox = [];

    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentRepositoryInterface $payments,
        private readonly PaymentStateManager $states,
        private readonly WebhookEventRepositoryInterface $webhookEvents,
        private readonly TransactionRunnerInterface $transactions,
        ?RefundRepositoryInterface $refunds = null,
        private readonly ?Dispatcher $events = null,
        ?LoggerInterface $logger = null,
        private readonly ?GatewayAvailabilityInterface $availability = null,
        ?PayloadRedactor $redactor = null,
        array $options = [],
    ) {
        $this->refunds = $refunds ?? new InMemoryRefundRepository();
        $this->logger = $logger ?? new NullLogger();
        $this->redactor = $redactor ?? new PayloadRedactor();
        $this->options = array_replace([
            'creation_lock_seconds' => 120,
            // %1$s reference, %2$d attempt, %3$s random token (collision-proof).
            'attempt_reference_format' => '%1$s-A%2$d-%3$s',
            'refund_unmatched_alert_hours' => 24,
            'expiry_grace_minutes' => 30,
            'reconcile_base_delay_seconds' => 300,
            'reconcile_max_delay_seconds' => 21600,
            'fulfilment_max_dispatches' => 10,
            'fulfilment_base_delay_seconds' => 300,
            'fulfilment_max_delay_seconds' => 21600,
        ], $options);
    }

    // ---------------------------------------------------------------------
    // Creation
    // ---------------------------------------------------------------------

    public function createPayment(GatewayName|string $gatewayName, CreatePaymentRequest $request): PaymentCreationResult
    {
        $gatewayKey = GatewayManager::keyOf($gatewayName);
        $gateway = $this->gateways->gateway($gatewayKey);

        if (!$gateway instanceof PaymentCreatorInterface) {
            throw new UnsupportedPaymentOperationException($gatewayKey, 'create_payment');
        }

        if ($this->availability !== null && !$this->availability->isEnabled($gatewayKey)) {
            throw new GatewayDisabledException($gatewayKey);
        }

        try {
            /** @var array{0: Payment, 1: bool} $reservation */
            $reservation = $this->atomic(fn (): array => $this->reserveCreation($gatewayKey, $request));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new PaymentCreationInProgressException($request->paymentReference);
            }

            throw $e;
        }

        [$payment, $replay] = $reservation;

        if ($replay) {
            return $this->creationResultFromPayment($payment);
        }

        try {
            $result = $gateway->createPayment($request->withGatewayOrderReference($payment->merchantOrderReference()));

            if ($result->amount !== null && !$request->amount->isEquivalentTo($result->amount)) {
                throw new PaymentAmountMismatchException($request->amount, $result->amount);
            }
        } catch (Throwable $e) {
            $this->markCreationFailed((string) $payment->reference, $e);

            throw $e;
        }

        $payment = $this->atomic(function () use ($payment, $result): Payment {
            $locked = $this->payments->findByReferenceForUpdate((string) $payment->reference) ?? $payment;

            $this->fillIdentifiers($locked, $result->gatewayReference, $result->gatewayTransactionId);
            $locked->checkout_url = $result->checkoutUrl;
            $locked->expires_at = $result->expiresAt;
            $locked->last_error = null;
            $locked->metadata = array_replace($locked->metadata ?? [], PayloadRedactor::scalarsOnly($result->metadata));

            $this->applyStatus($locked, $result->status, $result->gatewayStatus, null, 'creation');
            if ($locked->status === PaymentStatus::INITIATING) {
                $locked->status = PaymentStatus::PENDING;
            }

            $this->payments->save($locked);

            return $locked;
        });

        // Non-scalar gateway data (e.g. embedded-checkout parameters) is returned
        // to the caller but not persisted.
        return $this->creationResultFromPayment($payment, $result->metadata);
    }

    /** @return array{0: Payment, 1: bool} [payment, isReplay] */
    private function reserveCreation(string $gatewayKey, CreatePaymentRequest $request): array
    {
        $existing = $this->payments->findByReferenceForUpdate($request->paymentReference);

        if ($existing === null) {
            $payment = new Payment();
            $payment->reference = $request->paymentReference;
            $payment->gateway = $gatewayKey;
            $payment->status = PaymentStatus::INITIATING;
            $payment->setAmountMoney($request->amount);
            $payment->metadata = $request->metadata;
            $payment->attempt = 1;
            // The reference may already be another payment's merchant order id
            // (an earlier retry); never send the same id for two payments.
            $payment->gateway_order_reference = $this->payments->findByGatewayOrderReference($gatewayKey, $request->paymentReference) === null
                ? $request->paymentReference
                : $this->newAttemptReference($gatewayKey, $request->paymentReference, 1);
            $payment->refunded_amount_minor = 0;
            $payment->late_payment = false;
            $this->payments->save($payment);

            return [$payment, false];
        }

        if ($existing->gatewayKey() !== $gatewayKey) {
            throw new PaymentReferenceConflictException(
                $request->paymentReference,
                'it belongs to gateway [' . $existing->gatewayKey() . ']',
            );
        }

        if (!$existing->amountMoney()->isEquivalentTo($request->amount)) {
            throw new PaymentReferenceConflictException(
                $request->paymentReference,
                'it was created for a different amount or currency',
            );
        }

        $retryable = $existing->status === PaymentStatus::CREATION_FAILED
            || ($existing->status === PaymentStatus::INITIATING && $this->isStaleInitiation($existing));

        if ($retryable) {
            // A new merchant order id: the gateway may have created the previous
            // session even though we never saw its response (Kashier rejects
            // duplicate order ids with ERR_ORD_02).
            $attempt = max(1, (int) ($existing->attempt ?? 1)) + 1;
            $existing->attempt = $attempt;
            $existing->gateway_order_reference = $this->newAttemptReference($gatewayKey, (string) $existing->reference, $attempt);
            $existing->status = PaymentStatus::INITIATING;
            $existing->checkout_url = null;
            $existing->gateway_reference = null;
            $existing->last_error = null;
            $this->payments->save($existing);

            return [$existing, false];
        }

        if ($existing->status === PaymentStatus::INITIATING) {
            throw new PaymentCreationInProgressException($request->paymentReference);
        }

        return [$existing, true];
    }

    /**
     * A merchant order id no other payment uses. The random token makes a
     * collision with application references practically impossible; the
     * lookups and the unique index make it impossible.
     */
    private function newAttemptReference(string $gatewayKey, string $reference, int $attempt): string
    {
        for ($try = 0; $try < 5; $try++) {
            $candidate = sprintf(
                (string) $this->options['attempt_reference_format'],
                $reference,
                $attempt,
                strtolower(substr(bin2hex(random_bytes(4)), 0, 6)),
            );

            if ($this->payments->findByGatewayOrderReference($gatewayKey, $candidate) === null
                && $this->payments->findByReference($candidate) === null) {
                return $candidate;
            }
        }

        throw new PaymentCreationInProgressException($reference);
    }

    private function isStaleInitiation(Payment $payment): bool
    {
        $updatedAt = $payment->updated_at;
        if ($updatedAt === null) {
            return true;
        }

        return $updatedAt->getTimestamp() < time() - (int) $this->options['creation_lock_seconds'];
    }

    private function markCreationFailed(string $reference, Throwable $error): void
    {
        $this->logger->warning('payment.creation_failed', [
            'payment_reference' => $reference,
            'exception' => $error::class,
        ]);

        try {
            $this->atomic(function () use ($reference, $error): void {
                $payment = $this->payments->findByReferenceForUpdate($reference);
                if ($payment === null || $payment->status !== PaymentStatus::INITIATING) {
                    return;
                }

                $payment->status = PaymentStatus::CREATION_FAILED;
                $payment->last_error = $this->describeError($error);
                $this->payments->save($payment);
            });
        } catch (Throwable $inner) {
            $this->logger->error('payment.creation_failure_not_recorded', [
                'payment_reference' => $reference,
                'exception' => $inner::class,
            ]);
        }
    }

    private function creationResultFromPayment(Payment $payment, array $extraMetadata = []): PaymentCreationResult
    {
        return new PaymentCreationResult(
            paymentReference: (string) $payment->reference,
            status: $payment->status,
            checkoutUrl: $payment->checkout_url,
            gatewayReference: $payment->gateway_reference,
            gatewayTransactionId: $payment->gateway_transaction_id,
            gatewayStatus: $payment->gateway_status,
            expiresAt: $payment->expires_at,
            amount: $payment->amountMoney(),
            metadata: array_replace($payment->metadata ?? [], $extraMetadata),
        );
    }

    // ---------------------------------------------------------------------
    // Status and reconciliation
    // ---------------------------------------------------------------------

    /**
     * Queries the gateway using the identifiers stored on the payment.
     * Identifiers passed in $request (other than the reference) are ignored.
     */
    public function getPaymentStatus(PaymentStatusRequest $request): PaymentStatusResult
    {
        $payment = $this->requirePayment($request->paymentReference);
        $gateway = $this->gateways->gateway($payment->gatewayKey());

        if (!$gateway instanceof PaymentStatusProviderInterface) {
            throw new UnsupportedPaymentOperationException($payment->gatewayKey(), 'status_query');
        }

        $result = $gateway->getPaymentStatus($this->statusRequestFor($payment));
        $this->applyGatewayResult((string) $payment->reference, $result, 'status_query');

        return $result;
    }

    /** Same contract as getPaymentStatus(), using the gateway's reconciliation source. */
    public function reconcilePayment(PaymentStatusRequest $request): PaymentStatusResult
    {
        $payment = $this->requirePayment($request->paymentReference);
        $gateway = $this->gateways->gateway($payment->gatewayKey());

        if (!$gateway instanceof PaymentReconciliationInterface) {
            throw new UnsupportedPaymentOperationException($payment->gatewayKey(), 'reconciliation');
        }

        $result = $gateway->reconcilePayment($this->statusRequestFor($payment));
        $this->applyGatewayResult((string) $payment->reference, $result, 'reconciliation');
        $this->refreshRefundsFromGateway($gateway, (string) $payment->reference, 'reconciliation');

        return $result;
    }

    /** Closes an INITIATING record left behind by a request that crashed mid-creation. */
    public function failStaleInitiation(string $reference): bool
    {
        return $this->atomic(function () use ($reference): bool {
            $payment = $this->payments->findByReferenceForUpdate($reference);
            if ($payment === null || $payment->status !== PaymentStatus::INITIATING || !$this->isStaleInitiation($payment)) {
                return false;
            }

            $payment->status = PaymentStatus::CREATION_FAILED;
            $payment->last_error = 'Creation did not complete (process interrupted).';
            $this->payments->save($payment);

            return true;
        });
    }

    /** Marks an unpaid payment EXPIRED once its checkout expiry (plus grace) has passed. */
    public function expireIfDue(string $reference, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();
        $grace = (int) $this->options['expiry_grace_minutes'] * 60;

        return $this->atomic(function () use ($reference, $now, $grace): bool {
            $payment = $this->payments->findByReferenceForUpdate($reference);
            if ($payment === null || !in_array($payment->status, [PaymentStatus::PENDING, PaymentStatus::PROCESSING], true)) {
                return false;
            }

            $expiresAt = $payment->expires_at;
            if ($expiresAt === null || $expiresAt->getTimestamp() + $grace > $now->getTimestamp()) {
                return false;
            }

            $changed = $this->applyStatus($payment, PaymentStatus::EXPIRED, null, $now, 'expiry');
            $this->payments->save($payment);

            return $changed;
        });
    }

    /** Exponential back-off, so failing payments cannot starve the reconciliation batch. */
    public function scheduleNextReconciliation(string $reference, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        $this->atomic(function () use ($reference, $now): void {
            $payment = $this->payments->findByReferenceForUpdate($reference);
            if ($payment === null) {
                return;
            }

            $attempts = (int) ($payment->reconcile_attempts ?? 0) + 1;
            $delay = min(
                (int) $this->options['reconcile_max_delay_seconds'],
                (int) $this->options['reconcile_base_delay_seconds'] * (2 ** min($attempts - 1, 16)),
            );

            $payment->reconcile_attempts = $attempts;
            $payment->next_reconcile_at = $now->modify('+' . $delay . ' seconds');
            $this->payments->save($payment);
        });
    }

    // ---------------------------------------------------------------------
    // Fulfilment
    // ---------------------------------------------------------------------

    /**
     * Records that the application has handled PaymentSucceeded for this
     * payment (fulfilled the order, or decided not to, e.g. for a late
     * payment). Until then payments:redispatch-unfulfilled raises the event
     * again. Call it in the same database transaction as the fulfilment.
     *
     * Idempotent: returns false when the payment was already marked.
     */
    public function markFulfilled(string $reference, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $this->atomic(function () use ($reference, $now): bool {
            $payment = $this->payments->findByReferenceForUpdate($reference) ?? throw new PaymentNotFoundException($reference);

            if (!$payment->status->isPaid()) {
                throw new PaymentNotPaidException($reference, $payment->status);
            }

            if ($payment->fulfilled_at !== null) {
                return false;
            }

            $payment->fulfilled_at = $now;
            $payment->next_fulfilment_dispatch_at = null;
            $this->payments->save($payment);

            return true;
        });
    }

    /**
     * Raises PaymentSucceeded again for payments paid at or before $paidBefore
     * that were never marked fulfilled (a listener failed, a queue job was
     * lost). Each payment is retried with exponential back-off, at most
     * fulfilment_max_dispatches times. Returns the number of events raised.
     */
    public function redispatchUnfulfilled(DateTimeInterface $paidBefore, int $limit = 100, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $maxDispatches = max(1, (int) $this->options['fulfilment_max_dispatches']);
        $raised = 0;

        foreach ($this->payments->findUnfulfilledCandidates($paidBefore, $now, $maxDispatches, $limit) as $candidate) {
            $reference = (string) $candidate->reference;

            try {
                if ($this->redispatchFulfilment($reference, $paidBefore, $now, $maxDispatches)) {
                    $raised++;
                }
            } catch (Throwable $e) {
                $this->logger->error('payment.fulfilment_redispatch_failed', [
                    'payment_reference' => $reference,
                    'exception' => $e::class,
                ]);
            }
        }

        return $raised;
    }

    private function redispatchFulfilment(string $reference, DateTimeInterface $paidBefore, DateTimeImmutable $now, int $maxDispatches): bool
    {
        return $this->atomic(function () use ($reference, $paidBefore, $now, $maxDispatches): bool {
            $payment = $this->payments->findByReferenceForUpdate($reference);
            $dispatches = (int) ($payment?->fulfilment_dispatches ?? 0);
            $next = $payment?->next_fulfilment_dispatch_at;

            // Re-checked under the lock: markFulfilled() may have run since the lookup.
            if ($payment === null
                || $payment->fulfilled_at !== null
                || !$payment->status->awaitsFulfilment()
                || $payment->paid_at === null
                || $payment->paid_at->getTimestamp() > $paidBefore->getTimestamp()
                || $dispatches >= $maxDispatches
                || ($next !== null && $next->getTimestamp() > $now->getTimestamp())) {
                return false;
            }

            $dispatches++;
            $delay = min(
                (int) $this->options['fulfilment_max_delay_seconds'],
                (int) $this->options['fulfilment_base_delay_seconds'] * (2 ** min($dispatches - 1, 16)),
            );

            $payment->fulfilment_dispatches = $dispatches;
            $payment->next_fulfilment_dispatch_at = $now->modify('+' . $delay . ' seconds');
            $this->payments->save($payment);

            $this->logger->warning('payment.fulfilment_redispatched', [
                'payment_reference' => $reference,
                'gateway' => $payment->gateway,
                'dispatch' => $dispatches,
            ]);

            if ($dispatches >= $maxDispatches) {
                $this->logger->critical('payment.fulfilment_redispatch_exhausted', [
                    'payment_reference' => $reference,
                    'gateway' => $payment->gateway,
                    'dispatches' => $dispatches,
                ]);
            }

            $this->outbox[] = new PaymentSucceeded($reference, $payment->gatewayKey(), (bool) $payment->late_payment);

            return true;
        });
    }

    // ---------------------------------------------------------------------
    // Return (browser redirect) — informational only
    // ---------------------------------------------------------------------

    public function handleReturn(PaymentReturnRequest $request, GatewayName|string $gatewayName): PaymentReturnResult
    {
        $gatewayKey = GatewayManager::keyOf($gatewayName);
        $gateway = $this->gateways->gateway($gatewayKey);

        if (!$gateway instanceof PaymentReturnHandlerInterface) {
            throw new UnsupportedPaymentOperationException($gatewayKey, 'return_handling');
        }

        // Never changes state: the redirect is controlled by the customer's browser.
        $result = $gateway->handleReturn($request);

        $payment = null;
        if ($result->gatewayReference !== null && $result->gatewayReference !== '') {
            $payment = $this->payments->findByGatewayReference($gatewayKey, $result->gatewayReference);
        }
        if ($payment === null && $result->paymentReference !== '') {
            $payment = $this->payments->findByGatewayOrderReference($gatewayKey, $result->paymentReference)
                ?? $this->payments->findByReference($result->paymentReference);
        }

        if ($payment === null || $payment->gatewayKey() !== $gatewayKey) {
            return $result;
        }

        return $result->withLocalPayment((string) $payment->reference, $payment->status);
    }

    // ---------------------------------------------------------------------
    // Webhooks
    // ---------------------------------------------------------------------

    public function handleWebhook(WebhookRequest $request, GatewayName|string $gatewayName): WebhookResult
    {
        $gatewayKey = GatewayManager::keyOf($gatewayName);
        $gateway = $this->gateways->gateway($gatewayKey);

        if (!$gateway instanceof WebhookHandlerInterface) {
            throw new UnsupportedPaymentOperationException($gatewayKey, 'webhook');
        }

        // Signature and structure are verified by the gateway (WebhookVerificationException).
        $result = $gateway->handleWebhook($request);

        $eventId = $this->normaliseEventId($result->eventId);
        $isNew = $this->webhookEvents->storeIfAbsent(
            gateway: $gatewayKey,
            eventId: $eventId,
            eventType: mb_substr($result->eventType !== '' ? $result->eventType : 'unknown', 0, 100),
            paymentReference: mb_substr($result->paymentReference, 0, 255),
            payloadHash: hash('sha256', json_encode($result->rawPayload, JSON_THROW_ON_ERROR)),
            payload: $this->redactor->redact($result->rawPayload),
            status: WebhookEventStatus::RECEIVED,
        );

        if (!$isNew) {
            $existing = $this->webhookEvents->find($gatewayKey, $eventId);
            if ($existing !== null && $existing->status instanceof WebhookEventStatus && $existing->status->isFinal()) {
                return $result->asDuplicate();
            }
            // RECEIVED (interrupted or concurrent) or FAILED (retry): process again.
        }

        try {
            $payment = $this->resolveWebhookPayment($gatewayKey, $result);
            $refundList = $result->kind === WebhookEventKind::REFUND && $result->requiresVerification && $gateway instanceof RefundListingInterface
                ? $gateway->listRefunds($this->statusRequestFor($payment))
                : null;
            $verified = $refundList === null ? $this->verifyWebhookClaim($gateway, $payment, $result) : null;

            $outcome = $this->atomic(function () use ($gatewayKey, $eventId, $payment, $result, $verified, $refundList): array {
                $event = $this->webhookEvents->findForUpdate($gatewayKey, $eventId);
                if ($event !== null && $event->status instanceof WebhookEventStatus && $event->status->isFinal()) {
                    return ['duplicate' => true];
                }

                $locked = $this->payments->findByReferenceForUpdate((string) $payment->reference) ?? $payment;

                $note = match ($result->kind) {
                    WebhookEventKind::PAYMENT => $this->applyPaymentWebhook($locked, $result, $verified),
                    WebhookEventKind::REFUND => $refundList !== null
                        ? $this->applyListedRefundWebhook($locked, $result, $refundList)
                        : $this->applyRefundWebhook($locked, $result, $verified),
                    WebhookEventKind::ATTEMPT_FAILED => $this->applyAttemptFailure($locked, $result),
                    WebhookEventKind::IGNORED => 'ignored: ' . ($result->ignoreReason ?? 'not actionable'),
                };

                $this->payments->save($locked);
                $this->webhookEvents->markStatus(
                    $gatewayKey,
                    $eventId,
                    match (true) {
                        str_starts_with($note, 'ignored') => WebhookEventStatus::IGNORED,
                        // Not final: a later delivery of the same event is processed again.
                        str_starts_with($note, 'unconfirmed') => WebhookEventStatus::RECEIVED,
                        default => WebhookEventStatus::PROCESSED,
                    },
                    $note,
                    (string) $locked->reference,
                );

                return ['duplicate' => false, 'payment' => $locked, 'note' => $note];
            });
        } catch (Throwable $e) {
            $this->recordWebhookFailure($gatewayKey, $eventId, $e);

            throw $e;
        }

        if ($outcome['duplicate']) {
            return $result->asDuplicate();
        }

        /** @var Payment $updated */
        $updated = $outcome['payment'];

        return $result->withOutcome((string) $updated->reference, $updated->status, (string) $outcome['note']);
    }

    private function verifyWebhookClaim(
        PaymentGatewayInterface $gateway,
        Payment $payment,
        WebhookResult $result,
    ): ?PaymentStatusResult {
        if (!$result->requiresVerification || $result->kind === WebhookEventKind::IGNORED || $result->kind === WebhookEventKind::ATTEMPT_FAILED) {
            return null;
        }

        $request = $this->statusRequestFor($payment);
        $hasSession = $payment->gateway_reference !== null && $payment->gateway_reference !== '';

        if ($result->kind === WebhookEventKind::PAYMENT && $hasSession && $gateway instanceof PaymentStatusProviderInterface) {
            return $gateway->getPaymentStatus($request);
        }

        if ($gateway instanceof PaymentReconciliationInterface) {
            return $gateway->reconcilePayment($request);
        }

        if ($gateway instanceof PaymentStatusProviderInterface && $hasSession) {
            return $gateway->getPaymentStatus($request);
        }

        return null;
    }

    private function applyPaymentWebhook(Payment $payment, WebhookResult $result, ?PaymentStatusResult $verified): string
    {
        $unconfirmed = false;

        if ($result->requiresVerification) {
            if ($verified === null) {
                $this->logger->warning('payment.webhook_unverifiable', [
                    'payment_reference' => $payment->reference,
                    'gateway' => $payment->gateway,
                ]);

                return 'ignored: the claim could not be confirmed with the gateway API';
            }

            $this->assertGatewayResultMatches($payment, $verified);
            $target = $verified->status;
            $gatewayStatus = $verified->gatewayStatus ?? $result->gatewayStatus;
            $occurredAt = $verified->occurredAt ?? $result->occurredAt;
            $this->fillIdentifiers($payment, $verified->gatewayReference, $verified->gatewayTransactionId);

            $unconfirmed = $result->status === PaymentStatus::SUCCEEDED
                && ($target === null || $target->isUnpaid())
                && $payment->status->isUnpaid();

            if ($unconfirmed) {
                // Forged, or the gateway API has not caught up yet. Keep the event
                // re-processable (a genuine delivery shares its dedupe key) and
                // let reconciliation look again soon.
                $this->logger->warning('payment.webhook_success_not_confirmed', [
                    'payment_reference' => $payment->reference,
                    'gateway' => $payment->gateway,
                    'gateway_status' => $verified->gatewayStatus,
                ]);
                $payment->next_reconcile_at = new DateTimeImmutable('+60 seconds');
            }
        } else {
            if ($result->amount !== null && !$payment->amountMoney()->isEquivalentTo($result->amount)) {
                throw new PaymentAmountMismatchException($payment->amountMoney(), $result->amount);
            }

            $target = $result->status;
            $gatewayStatus = $result->gatewayStatus;
            $occurredAt = $result->occurredAt;
            $this->fillIdentifiers($payment, $result->gatewayReference, $result->gatewayTransactionId);
        }

        if ($target === null) {
            return $unconfirmed
                ? 'unconfirmed: the gateway does not report this payment as paid yet'
                : 'ignored: unrecognised gateway status';
        }

        $changed = $this->applyStatus($payment, $this->paymentStateOf($target), $gatewayStatus, $occurredAt, 'webhook');

        if ($verified?->refundedAmount !== null && $payment->status->isPaid()) {
            $this->syncRefundTotal($payment, $verified->refundedAmount, 'webhook');
        }

        $payment->metadata = array_replace($payment->metadata ?? [], PayloadRedactor::scalarsOnly($result->metadata));

        if ($unconfirmed && !$changed) {
            return 'unconfirmed: the gateway does not report this payment as paid yet';
        }

        return $changed
            ? 'applied: ' . $payment->status->value
            : 'ignored: no state change (current ' . $payment->status->value . ')';
    }

    private function applyAttemptFailure(Payment $payment, WebhookResult $result): string
    {
        $reason = isset($result->metadata['failure_reason']) ? (string) $result->metadata['failure_reason'] : null;

        $payment->metadata = array_replace($payment->metadata ?? [], [
            'last_attempt_status' => $result->gatewayStatus,
            'last_attempt_reason' => $reason,
            'last_attempt_at' => ($result->occurredAt ?? new DateTimeImmutable())->format(DATE_ATOM),
        ]);

        if ($payment->status->isPaid()) {
            return 'ignored: attempt failure received after the payment succeeded';
        }

        $this->outbox[] = new PaymentAttemptFailed(
            (string) $payment->reference,
            $payment->gatewayKey(),
            $result->gatewayStatus,
            $reason,
        );

        return 'applied: attempt failure recorded, payment remains ' . $payment->status->value;
    }

    private function applyRefundWebhook(Payment $payment, WebhookResult $result, ?PaymentStatusResult $verified): string
    {
        $notice = $result->refund;
        if ($notice === null) {
            return 'ignored: refund notification without refund data';
        }

        $currency = $payment->amountMoney()->currency;
        if ($notice->amount->currency->code !== $currency->code) {
            throw new PaymentAmountMismatchException($payment->amountMoney(), $notice->amount);
        }
        $amount = $notice->amount->toCurrencyScale($currency);

        if ($verified !== null) {
            $this->assertGatewayResultMatches($payment, $verified);
        }

        // A void or reversal before capture cancels the payment rather than refunding it.
        if ($notice->isCancellation() && $payment->status->isUnpaid()) {
            if ($notice->status !== RefundStatus::SUCCEEDED) {
                return 'ignored: unsuccessful ' . $notice->type . ' before capture';
            }

            // A claim that needs verification only cancels when the gateway API agrees
            // that the payment is closed; otherwise a replayed notification could
            // cancel a payment that is about to be captured.
            if ($result->requiresVerification && !($verified?->status?->isClosedUnpaid() ?? false)) {
                $payment->next_reconcile_at = new DateTimeImmutable('+5 minutes');

                return 'unconfirmed: the gateway does not report this payment as cancelled';
            }

            $target = $verified?->status !== null && $verified->status->isClosedUnpaid() ? $verified->status : PaymentStatus::CANCELLED;
            $changed = $this->applyStatus($payment, $target, $result->gatewayStatus, $result->occurredAt, 'webhook_' . $notice->type);

            return $changed ? 'applied: cancelled by ' . $notice->type : 'ignored: no state change';
        }

        $existing = null;
        if ($notice->refundReference !== null && $notice->refundReference !== '') {
            $existing = $this->refunds->findByGatewayReference($payment->gatewayKey(), $notice->refundReference);
        }

        if ($notice->status !== RefundStatus::SUCCEEDED) {
            // Pending and failed notices only update a refund the ledger already
            // knows; they never create one (a new pending row would reserve money).
            $row = $existing ?? $this->refunds->findOldestUnmatchedPending((string) $payment->reference, $amount->minorAmount);
            if ($row === null) {
                return 'ignored: ' . $notice->status->value . ' ' . $notice->type . ' without a matching local refund';
            }

            $this->applyRefundOutcome($row, $notice->status, $notice->refundReference, null);
            $this->refunds->save($row);
            $this->recalculateRefunds($payment, null, 'webhook');

            return 'applied: ' . $notice->type . ' ' . $notice->status->value;
        }

        // A successful refund is booked only as far as the gateway's own refunded
        // total confirms it, so a replayed or relabelled notification cannot add
        // money that the gateway never returned.
        $gatewayTotal = $verified?->refundedAmount;
        if ($result->requiresVerification && $existing?->status !== RefundStatus::SUCCEEDED) {
            if ($gatewayTotal === null || $gatewayTotal->currency->code !== $currency->code) {
                $payment->next_reconcile_at = new DateTimeImmutable('+5 minutes');

                return 'unconfirmed: the gateway did not report a refunded total';
            }

            $confirmedMinor = $gatewayTotal->toCurrencyScale($currency)->minorAmount
                - $this->refunds->sumAmount((string) $payment->reference, RefundStatus::SUCCEEDED);

            if ($confirmedMinor < $amount->minorAmount) {
                $payment->next_reconcile_at = new DateTimeImmutable('+5 minutes');

                return 'unconfirmed: the gateway refunded total does not include this refund';
            }
        }

        if ($payment->status->isUnpaid()) {
            // A confirmed refund proves the payment was collected; its own
            // notification was lost or has not arrived yet.
            $this->applyStatus($payment, PaymentStatus::SUCCEEDED, null, null, 'refund_implies_payment');
        }

        $refund = $existing
            ?? $this->refunds->findOldestUnmatchedPending((string) $payment->reference, $amount->minorAmount)
            ?? $this->newRefund($payment, $amount, PaymentRefund::SOURCE_WEBHOOK, $notice->type);

        $this->applyRefundOutcome($refund, $notice->status, $notice->refundReference, $amount);
        $this->refunds->save($refund);

        $this->recalculateRefunds($payment, $gatewayTotal ?? $notice->gatewayTotalRefunded, 'webhook');

        return 'applied: ' . $notice->type . ' ' . $notice->status->value;
    }

    /**
     * The notification only triggers a refresh: the refund list from the
     * gateway API decides what is recorded, so a replayed or altered
     * notification cannot add or approve a refund.
     */
    private function applyListedRefundWebhook(Payment $payment, WebhookResult $result, GatewayRefundList $list): string
    {
        $note = $this->syncRefundList($payment, $list, 'webhook');
        if (str_starts_with($note, 'unconfirmed')) {
            return $note;
        }

        $notice = $result->refund;
        if ($notice !== null && $notice->status === RefundStatus::SUCCEEDED) {
            $currency = $payment->amountMoney()->currency;
            $claimed = $notice->amount->currency->code === $currency->code ? $notice->amount->toCurrencyScale($currency)->minorAmount : null;
            foreach ($list->refunds as $record) {
                if ($record->status === RefundStatus::SUCCEEDED && $claimed !== null
                    && $record->amount->toCurrencyScale($currency)->minorAmount === $claimed) {
                    return 'applied: refund confirmed by the gateway refund list';
                }
            }

            $payment->next_reconcile_at = new DateTimeImmutable('+5 minutes');

            return 'unconfirmed: the gateway refund list does not show this refund as approved yet';
        }

        return $note;
    }

    /**
     * Makes the ledger match the gateway's refund list: one row per gateway
     * refund id, local pending requests matched oldest-first by amount.
     */
    private function syncRefundList(Payment $payment, GatewayRefundList $list, string $source): string
    {
        $currency = $payment->amountMoney()->currency;
        $amountMinor = $payment->amountMoney()->minorAmount;
        $reference = (string) $payment->reference;

        $listed = 0;
        foreach ($list->refunds as $record) {
            if ($record->amount->currency->code !== $currency->code) {
                $this->logger->critical('payment.refund_list_currency_mismatch', ['payment_reference' => $reference]);

                return 'unconfirmed: the gateway refund list uses another currency';
            }
            if ($record->status !== RefundStatus::FAILED) {
                $listed += $record->amount->toCurrencyScale($currency)->minorAmount;
            }
        }

        if ($listed > $amountMinor) {
            // Never book more than was paid: the list probably mixes in another
            // payment's refunds (ids from different tables can overlap).
            $this->logger->critical('payment.refund_list_exceeds_amount', [
                'payment_reference' => $reference,
                'listed_minor' => $listed,
                'amount_minor' => $amountMinor,
            ]);

            return 'unconfirmed: the gateway refund list exceeds the payment amount; review manually';
        }

        $hasApproved = false;
        foreach ($list->refunds as $record) {
            $hasApproved = $hasApproved || $record->status === RefundStatus::SUCCEEDED;
        }

        if ($hasApproved && $payment->status->isUnpaid()) {
            // Approved refunds prove the payment was collected.
            $this->applyStatus($payment, PaymentStatus::SUCCEEDED, null, null, 'refund_implies_payment');
        }

        foreach ($list->refunds as $record) {
            $amount = $record->amount->toCurrencyScale($currency);
            $row = $this->refunds->findByGatewayReference($payment->gatewayKey(), $record->reference)
                ?? $this->refunds->findOldestUnmatchedPending($reference, $amount->minorAmount)
                ?? $this->newRefund($payment, $amount, $source === 'webhook' ? PaymentRefund::SOURCE_WEBHOOK : PaymentRefund::SOURCE_RECONCILIATION, RefundNotification::TYPE_REFUND);

            $this->applyRefundOutcome($row, $record->status, $record->reference, $record->status === RefundStatus::SUCCEEDED ? $amount : null);
            $this->refunds->save($row);
        }

        if ($list->complete) {
            $threshold = time() - (int) $this->options['refund_unmatched_alert_hours'] * 3600;
            foreach ($this->refunds->pendingFor($reference) as $pending) {
                $created = $pending->created_at;
                if (($pending->gateway_refund_reference === null || $pending->gateway_refund_reference === '')
                    && $created !== null && $created->getTimestamp() < $threshold && !$pending->outcome_unknown) {
                    // Not at the gateway at all. Never failed automatically: an
                    // operator decides (payments:resolve-refund).
                    $pending->outcome_unknown = true;
                    $pending->last_error = 'Not found in the gateway refund list; review manually.';
                    $this->refunds->save($pending);
                    $this->logger->critical('payment.refund_missing_at_gateway', [
                        'payment_reference' => $reference,
                        'refund_id' => $pending->uuid,
                    ]);
                }
            }
        }

        $this->recalculateRefunds($payment, null, $source);

        return 'applied: refunds synchronised with the gateway list';
    }

    /** Pulls the gateway refund list (when supported) into the ledger. */
    private function refreshRefundsFromGateway(PaymentGatewayInterface $gateway, string $reference, string $source): void
    {
        if (!$gateway instanceof RefundListingInterface) {
            return;
        }

        $payment = $this->requirePayment($reference);
        if ($payment->status->isUnpaid() && $this->refunds->pendingFor($reference) === []) {
            return;
        }

        $list = $gateway->listRefunds($this->statusRequestFor($payment));

        $this->atomic(function () use ($reference, $list, $source): void {
            $locked = $this->payments->findByReferenceForUpdate($reference) ?? throw new PaymentNotFoundException($reference);
            $this->syncRefundList($locked, $list, $source);
            $this->payments->save($locked);
        });
    }

    /** Signed identifiers first; the merchant reference only when the gateway signs it. */
    private function resolveWebhookPayment(string $gatewayKey, WebhookResult $result): Payment
    {
        $payment = null;

        if ($result->gatewayReference !== null && $result->gatewayReference !== '') {
            $payment = $this->payments->findByGatewayReference($gatewayKey, $result->gatewayReference);
        }

        if ($payment === null && $result->gatewayTransactionId !== null && $result->gatewayTransactionId !== '') {
            $payment = $this->payments->findByGatewayTransactionId($gatewayKey, $result->gatewayTransactionId);
        }

        if ($payment === null && $result->referenceTrusted && $result->paymentReference !== '') {
            $payment = $this->payments->findByGatewayOrderReference($gatewayKey, $result->paymentReference);

            if ($payment === null) {
                $candidate = $this->payments->findByReference($result->paymentReference);
                $payment = $candidate !== null && $candidate->gatewayKey() === $gatewayKey ? $candidate : null;
            }
        }

        if ($payment === null) {
            throw new PaymentNotFoundException(
                $result->paymentReference !== ''
                    ? $result->paymentReference
                    : ($result->gatewayTransactionId ?? $result->gatewayReference ?? 'unknown'),
            );
        }

        if ($result->paymentReference !== '' && !$this->belongsToPayment($payment, $result->paymentReference)) {
            $this->logger->warning('payment.webhook_reference_mismatch', [
                'payment_reference' => $payment->reference,
                'gateway' => $gatewayKey,
                'reported_reference' => $result->paymentReference,
                'reference_trusted' => $result->referenceTrusted,
            ]);
        }

        return $payment;
    }

    private function recordWebhookFailure(string $gatewayKey, string $eventId, Throwable $error): void
    {
        $this->logger->error('payment.webhook_failed', [
            'gateway' => $gatewayKey,
            'event_id' => $eventId,
            'exception' => $error::class,
        ]);

        try {
            $this->webhookEvents->markStatus($gatewayKey, $eventId, WebhookEventStatus::FAILED, $this->describeError($error));
        } catch (Throwable) {
            // The original error is more useful to the caller.
        }
    }

    private function normaliseEventId(string $eventId): string
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            throw new WebhookVerificationException('Webhook event has no identifier.');
        }

        return strlen($eventId) > 191 ? 'sha256:' . hash('sha256', $eventId) : $eventId;
    }

    // ---------------------------------------------------------------------
    // Refunds
    // ---------------------------------------------------------------------

    public function refund(RefundPaymentRequest $request): RefundResult
    {
        $payment = $this->requirePayment($request->paymentReference);
        $gatewayKey = $payment->gatewayKey();
        $gateway = $this->gateways->gateway($gatewayKey);

        if (!$gateway instanceof PaymentRefundInterface) {
            throw new UnsupportedPaymentOperationException($gatewayKey, 'refund');
        }

        $this->assertNoReservedMetadata($gateway, $request->metadata);

        $idempotencyKey = $request->idempotencyKey !== null && $request->idempotencyKey !== '' ? $request->idempotencyKey : null;
        if ($idempotencyKey !== null) {
            $existing = $this->refunds->findByIdempotencyKey((string) $payment->reference, $idempotencyKey);
            if ($existing !== null) {
                return $this->refundResultFromLedger($existing, true);
            }
        }

        // Pull the gateway's view first so refunds made elsewhere are counted.
        if ($gateway instanceof PaymentReconciliationInterface) {
            $fresh = $gateway->reconcilePayment($this->statusRequestFor($payment));
            $payment = $this->applyGatewayResult((string) $payment->reference, $fresh, 'refund_refresh');
        }
        $this->refreshRefundsFromGateway($gateway, (string) $payment->reference, 'refund_refresh');
        $payment = $this->requirePayment((string) $payment->reference);

        try {
            /** @var PaymentRefund|RefundResult $refund */
            $refund = $this->atomic(function () use ($request, $gateway, $gatewayKey, $idempotencyKey): PaymentRefund|RefundResult {
                $locked = $this->payments->findByReferenceForUpdate($request->paymentReference)
                    ?? throw new PaymentNotFoundException($request->paymentReference);

                // Re-checked under the payment lock: a concurrent request with the
                // same key either committed already (replay) or waits for us.
                if ($idempotencyKey !== null) {
                    $existing = $this->refunds->findByIdempotencyKey((string) $locked->reference, $idempotencyKey);
                    if ($existing !== null) {
                        return $this->refundResultFromLedger($existing, true);
                    }
                }

                if (!$locked->status->isRefundable()) {
                    throw new InvalidRefundException('Payment is not in a refundable state.');
                }

                $total = $locked->amountMoney();
                $reference = (string) $locked->reference;
                $succeeded = $this->refunds->sumAmount($reference, RefundStatus::SUCCEEDED);
                $pending = $this->refunds->sumAmount($reference, RefundStatus::PENDING);
                $availableMinor = $total->minorAmount - $succeeded - $pending;

                if ($availableMinor <= 0) {
                    throw new InvalidRefundException($pending > 0
                        ? 'Payment has no refundable amount remaining; pending refunds reserve the rest.'
                        : 'Payment has no refundable amount remaining.');
                }

                $amount = $request->amount ?? Money::fromMinorUnits($availableMinor, $total->currency);
                if ($amount->currency->code !== $total->currency->code) {
                    throw new InvalidRefundException('Refund currency must match the payment currency.');
                }

                $amount = $amount->toCurrencyScale($total->currency);
                if ($amount->minorAmount <= 0) {
                    throw new InvalidRefundException('Refund amount must be greater than zero.');
                }
                if ($amount->minorAmount > $availableMinor) {
                    throw new InvalidRefundException('Refund amount exceeds the remaining refundable amount.');
                }
                if ($amount->minorAmount < $total->minorAmount && !$gateway->capabilities()->supportsPartialRefunds()) {
                    throw new UnsupportedPaymentOperationException($gatewayKey, 'partial_refund');
                }

                // The pending row reserves the amount, so concurrent requests cannot over-refund.
                $refund = $this->newRefund($locked, $amount, PaymentRefund::SOURCE_API, RefundNotification::TYPE_REFUND);
                $refund->idempotency_key = $idempotencyKey;
                $refund->reason = $request->reason !== null ? mb_substr($request->reason, 0, 500) : null;
                $this->refunds->save($refund);

                return $refund;
            });
        } catch (QueryException $e) {
            $existing = $idempotencyKey !== null && $this->isUniqueViolation($e)
                ? $this->refunds->findByIdempotencyKey((string) $payment->reference, $idempotencyKey)
                : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->refundResultFromLedger($existing, true);
        }

        if ($refund instanceof RefundResult) {
            return $refund;
        }

        $gatewayMetadata = array_replace(
            PayloadRedactor::scalarsOnly($payment->metadata ?? []),
            $request->metadata,
            [
                'gateway_reference' => $payment->gateway_reference,
                'gateway_transaction_id' => $payment->gateway_transaction_id,
                'gateway_order_reference' => $payment->merchantOrderReference(),
            ],
        );

        try {
            $result = $gateway->refund(new RefundPaymentRequest(
                paymentReference: (string) $payment->reference,
                amount: $refund->amountMoney(),
                reason: $request->reason,
                idempotencyKey: $idempotencyKey ?? (string) $refund->uuid,
                metadata: $gatewayMetadata,
            ));
        } catch (Throwable $e) {
            // The gateway may or may not have refunded. Keep the reservation;
            // reconciliation settles it from the gateway totals.
            $this->markRefundOutcomeUnknown((string) $refund->uuid, $e);

            throw $e;
        }

        $anomaly = $this->atomic(function () use ($refund, $result): ?string {
            $locked = $this->payments->findByReferenceForUpdate((string) $refund->payment_reference)
                ?? throw new PaymentNotFoundException((string) $refund->payment_reference);
            $row = $this->refunds->findByUuid((string) $refund->uuid) ?? $refund;
            $requested = $row->amountMoney();

            $applied = $result->amount;
            if ($applied !== null && $applied->currency->code !== $requested->currency->code) {
                $row->outcome_unknown = true;
                $row->last_error = 'Gateway reported the refund in a different currency; review manually.';
                $this->refunds->save($row);

                return 'Gateway refunded in a different currency than requested.';
            }
            $applied = $applied?->toCurrencyScale($requested->currency);

            if ($result->status === RefundStatus::SUCCEEDED && $applied !== null && $applied->minorAmount > $requested->minorAmount) {
                $row->outcome_unknown = true;
                $row->last_error = 'Gateway reported a larger refund than requested; review manually.';
                if ($result->refundReference !== null && ($row->gateway_refund_reference === null || $row->gateway_refund_reference === '')) {
                    $row->gateway_refund_reference = $result->refundReference;
                }
                $this->refunds->save($row);

                return 'Gateway refunded amount exceeds the requested refund amount.';
            }

            $status = $result->status === RefundStatus::PROCESSING ? RefundStatus::PENDING : $result->status;
            $this->applyRefundOutcome($row, $status, $result->refundReference, $status === RefundStatus::SUCCEEDED ? $applied : null);
            if ($status === RefundStatus::FAILED) {
                $row->last_error = mb_substr('Gateway status: ' . ($result->gatewayStatus ?? 'unknown'), 0, 500);
            }
            $this->refunds->save($row);

            // A gateway-reported cumulative total also captures refunds made elsewhere.
            $this->recalculateRefunds($locked, $status === RefundStatus::SUCCEEDED ? $result->totalRefunded : null, 'refund_api');
            $this->payments->save($locked);

            return null;
        });

        if ($anomaly !== null) {
            $this->logger->critical('payment.refund_anomaly', [
                'payment_reference' => $refund->payment_reference,
                'refund_id' => $refund->uuid,
                'reason' => $anomaly,
            ]);

            throw new InvalidRefundException($anomaly);
        }

        return new RefundResult(
            paymentReference: (string) $payment->reference,
            status: $result->status,
            amount: $result->amount ?? $refund->amountMoney(),
            refundReference: $result->refundReference,
            gatewayStatus: $result->gatewayStatus,
            occurredAt: $result->occurredAt,
            metadata: array_replace($result->metadata, ['refund_id' => (string) $refund->uuid]),
            totalRefunded: $result->totalRefunded,
        );
    }

    /**
     * Operator decision for a pending refund whose outcome could not be
     * established automatically (e.g. a timeout and no trace at the gateway).
     */
    public function resolveRefund(string $refundId, RefundStatus $status, ?string $gatewayReference = null): PaymentRefund
    {
        if ($status !== RefundStatus::SUCCEEDED && $status !== RefundStatus::FAILED) {
            throw new InvalidRefundException('A refund can only be resolved as succeeded or failed.');
        }

        $refund = $this->refunds->findByUuid($refundId) ?? throw new InvalidRefundException('Refund not found.');

        return $this->atomic(function () use ($refund, $status, $gatewayReference): PaymentRefund {
            $payment = $this->payments->findByReferenceForUpdate((string) $refund->payment_reference)
                ?? throw new PaymentNotFoundException((string) $refund->payment_reference);
            $row = $this->refunds->findByUuid((string) $refund->uuid) ?? $refund;

            if ($row->status !== RefundStatus::PENDING) {
                throw new InvalidRefundException('Only pending refunds can be resolved.');
            }

            $this->applyRefundOutcome($row, $status, $gatewayReference, $status === RefundStatus::SUCCEEDED ? $row->amountMoney() : null);
            $row->last_error = 'Resolved manually as ' . $status->value . '.';
            $this->refunds->save($row);

            $this->recalculateRefunds($payment, null, 'manual');
            $this->payments->save($payment);

            $this->logger->warning('payment.refund_resolved_manually', [
                'payment_reference' => $payment->reference,
                'refund_id' => $row->uuid,
                'status' => $status->value,
            ]);

            return $row;
        });
    }

    private function newRefund(Payment $payment, Money $amount, string $source, string $type): PaymentRefund
    {
        $refund = new PaymentRefund();
        $refund->uuid = Uuid::v4();
        $refund->payment_reference = (string) $payment->reference;
        $refund->gateway = $payment->gatewayKey();
        $refund->status = RefundStatus::PENDING;
        $refund->type = $type;
        $refund->source = $source;
        $refund->amount_minor = $amount->minorAmount;
        $refund->currency = $amount->currency->code;
        $refund->currency_scale = $amount->currency->fractionDigits;
        $refund->outcome_unknown = false;

        return $refund;
    }

    private function applyRefundOutcome(PaymentRefund $refund, RefundStatus $status, ?string $gatewayReference, ?Money $amount): void
    {
        if ($gatewayReference !== null && $gatewayReference !== ''
            && ($refund->gateway_refund_reference === null || $refund->gateway_refund_reference === '')) {
            $refund->gateway_refund_reference = $gatewayReference;
        }

        if ($status === RefundStatus::PROCESSING) {
            $status = RefundStatus::PENDING;
        }

        // A confirmed refund is final; a failed one may still be confirmed later.
        if ($refund->status === RefundStatus::SUCCEEDED || ($refund->status === RefundStatus::FAILED && $status !== RefundStatus::SUCCEEDED)) {
            return;
        }

        $refund->status = $status;

        if ($status === RefundStatus::SUCCEEDED && $amount !== null) {
            $refund->amount_minor = $amount->minorAmount;
        }

        if ($status === RefundStatus::SUCCEEDED || $status === RefundStatus::FAILED) {
            $refund->completed_at = new DateTimeImmutable();
            $refund->outcome_unknown = false;
        }
    }

    /**
     * Brings the ledger in line with a gateway-reported refunded total:
     * settles pending rows first, then records any difference as a refund
     * made outside the application.
     */
    private function syncRefundTotal(Payment $payment, Money $gatewayTotal, string $source): void
    {
        $currency = $payment->amountMoney()->currency;
        if ($gatewayTotal->currency->code !== $currency->code) {
            $this->logger->warning('payment.refund_total_currency_mismatch', [
                'payment_reference' => $payment->reference,
                'gateway_currency' => $gatewayTotal->currency->code,
            ]);

            return;
        }

        $reference = (string) $payment->reference;
        $total = $gatewayTotal->toCurrencyScale($currency)->minorAmount;
        $succeeded = $this->refunds->sumAmount($reference, RefundStatus::SUCCEEDED);

        if ($total > $succeeded) {
            foreach ($this->refunds->pendingFor($reference) as $pending) {
                if ($succeeded + (int) $pending->amount_minor > $total) {
                    continue;
                }

                $this->applyRefundOutcome($pending, RefundStatus::SUCCEEDED, null, null);
                $this->refunds->save($pending);
                $succeeded += (int) $pending->amount_minor;
            }

            if ($total > $succeeded) {
                $adjustment = $this->newRefund(
                    $payment,
                    Money::fromMinorUnits($total - $succeeded, $currency),
                    PaymentRefund::SOURCE_RECONCILIATION,
                    RefundNotification::TYPE_REFUND,
                );
                $adjustment->reason = 'Refund reported by the gateway without a matching local request.';
                $this->applyRefundOutcome($adjustment, RefundStatus::SUCCEEDED, null, null);
                $this->refunds->save($adjustment);

                $this->logger->warning('payment.refund_discovered', [
                    'payment_reference' => $reference,
                    'amount_minor' => $total - $succeeded,
                    'source' => $source,
                ]);
            }
        } elseif ($total < $succeeded) {
            $this->logger->warning('payment.refund_ledger_exceeds_gateway_total', [
                'payment_reference' => $reference,
                'ledger_minor' => $succeeded,
                'gateway_minor' => $total,
            ]);
        }

        $this->recalculateRefunds($payment, null, $source);
    }

    private function recalculateRefunds(Payment $payment, ?Money $gatewayTotal, string $source): void
    {
        if ($gatewayTotal !== null) {
            $this->syncRefundTotal($payment, $gatewayTotal, $source);

            return;
        }

        $reference = (string) $payment->reference;
        $amount = $payment->amountMoney();
        $refundedMinor = $this->refunds->sumAmount($reference, RefundStatus::SUCCEEDED);
        $previous = (int) ($payment->refunded_amount_minor ?? 0);

        $payment->refunded_amount_minor = $refundedMinor;
        $payment->metadata = array_replace($payment->metadata ?? [], [
            'refunded_amount_minor' => $refundedMinor,
            'refunded_currency' => $amount->currency->code,
            'refunded_amount_scale' => $amount->currency->fractionDigits,
        ]);

        if ($refundedMinor > $amount->minorAmount) {
            $this->logger->critical('payment.refunded_total_exceeds_amount', [
                'payment_reference' => $reference,
                'refunded_minor' => $refundedMinor,
                'amount_minor' => $amount->minorAmount,
            ]);
        }

        if ($refundedMinor <= 0) {
            return;
        }

        $fully = $refundedMinor >= $amount->minorAmount;
        $this->applyStatus($payment, $fully ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED, null, null, $source);

        if ($fully && $payment->refunded_at === null) {
            $payment->refunded_at = new DateTimeImmutable();
        }

        if ($refundedMinor !== $previous) {
            $this->outbox[] = new PaymentRefunded(
                $reference,
                $payment->gatewayKey(),
                $refundedMinor,
                $amount->currency->code,
                $fully,
                $source,
            );
        }
    }

    private function markRefundOutcomeUnknown(string $uuid, Throwable $error): void
    {
        $this->logger->error('payment.refund_outcome_unknown', [
            'refund_id' => $uuid,
            'exception' => $error::class,
        ]);

        try {
            $this->atomic(function () use ($uuid, $error): void {
                $row = $this->refunds->findByUuid($uuid);
                if ($row === null || $row->status !== RefundStatus::PENDING) {
                    return;
                }

                $row->outcome_unknown = true;
                $row->last_error = $this->describeError($error);
                $this->refunds->save($row);
            });
        } catch (Throwable) {
            // Keep the original error.
        }
    }

    private function refundResultFromLedger(PaymentRefund $refund, bool $replay): RefundResult
    {
        return new RefundResult(
            paymentReference: (string) $refund->payment_reference,
            status: $refund->status,
            amount: $refund->amountMoney(),
            refundReference: $refund->gateway_refund_reference,
            gatewayStatus: null,
            occurredAt: $refund->completed_at,
            metadata: ['refund_id' => (string) $refund->uuid, 'idempotent_replay' => $replay],
        );
    }

    private function assertNoReservedMetadata(PaymentGatewayInterface $gateway, array $metadata): void
    {
        $reserved = self::GENERIC_RESERVED_KEYS;
        if ($gateway instanceof DeclaresReservedMetadataKeys) {
            $reserved = array_merge($reserved, $gateway->reservedMetadataKeys());
        }

        foreach ($reserved as $key) {
            if (array_key_exists($key, $metadata)) {
                throw new InvalidRefundException("Refund metadata key [{$key}] is reserved and cannot be overridden.");
            }
        }
    }

    // ---------------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------------

    /**
     * Applies a status/reconciliation result. Refund states reported by a
     * gateway only confirm that money was collected; refund totals come from
     * refundedAmount and the ledger.
     */
    private function applyGatewayResult(string $reference, PaymentStatusResult $result, string $source): Payment
    {
        return $this->atomic(function () use ($reference, $result, $source): Payment {
            $payment = $this->payments->findByReferenceForUpdate($reference) ?? throw new PaymentNotFoundException($reference);

            $this->assertGatewayResultMatches($payment, $result);

            // Identifiers first: whether the transaction id may be replaced depends
            // on the status before this update.
            $this->fillIdentifiers($payment, $result->gatewayReference, $result->gatewayTransactionId);

            if ($result->status !== null) {
                $this->applyStatus($payment, $this->paymentStateOf($result->status), $result->gatewayStatus, $result->occurredAt, $source);
            }
            $payment->metadata = array_replace($payment->metadata ?? [], PayloadRedactor::scalarsOnly($result->metadata));

            if ($result->refundedAmount !== null && $payment->status->isPaid()) {
                $this->syncRefundTotal($payment, $result->refundedAmount, $source);
            }

            $this->payments->save($payment);

            return $payment;
        });
    }

    private function paymentStateOf(PaymentStatus $status): PaymentStatus
    {
        return in_array($status, [PaymentStatus::REFUNDING, PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::REFUNDED], true)
            ? PaymentStatus::SUCCEEDED
            : $status;
    }

    /** Returns true when the stored status changed. Never throws for ordering problems. */
    private function applyStatus(
        Payment $payment,
        PaymentStatus $to,
        ?string $gatewayStatus,
        ?DateTimeImmutable $occurredAt,
        string $source,
    ): bool {
        $from = $payment->status;
        $decision = $this->states->decide($from, $to);

        if ($decision === TransitionDecision::STALE || $decision === TransitionDecision::INVALID) {
            $this->logger->info('payment.status_update_ignored', [
                'payment_reference' => $payment->reference,
                'gateway' => $payment->gateway,
                'from' => $from->value,
                'to' => $to->value,
                'decision' => $decision->value,
                'source' => $source,
            ]);

            return false;
        }

        if ($gatewayStatus !== null && $gatewayStatus !== '') {
            $payment->gateway_status = mb_substr($gatewayStatus, 0, 255);
        }

        if (!$decision->changesState()) {
            return false;
        }

        $payment->status = $to;
        $reference = (string) $payment->reference;
        $gateway = $payment->gatewayKey();

        if ($to === PaymentStatus::SUCCEEDED && $payment->paid_at === null) {
            $payment->paid_at = $occurredAt ?? new DateTimeImmutable();
        }

        if ($to === PaymentStatus::SUCCEEDED && $payment->fulfilled_at === null) {
            // PaymentSucceeded is raised now; paid_at may be much older (a late
            // notification), so give its listeners time before any redispatch.
            $payment->next_fulfilment_dispatch_at = (new DateTimeImmutable())
                ->modify('+' . (int) $this->options['fulfilment_base_delay_seconds'] . ' seconds');
        }

        if ($decision === TransitionDecision::LATE_PAYMENT) {
            $payment->late_payment = true;
            $this->logger->warning('payment.late_payment', [
                'payment_reference' => $reference,
                'gateway' => $gateway,
                'previous_status' => $from->value,
            ]);
            $this->outbox[] = new LatePaymentReceived($reference, $gateway, $from->value);
        }

        $this->outbox[] = new PaymentStatusChanged($reference, $gateway, $from->value, $to->value, $source);

        if ($to === PaymentStatus::SUCCEEDED) {
            $this->outbox[] = new PaymentSucceeded($reference, $gateway, $decision === TransitionDecision::LATE_PAYMENT);
        } elseif ($to->isClosedUnpaid()) {
            $this->outbox[] = new PaymentClosed($reference, $gateway, $to->value);
        }

        return true;
    }

    private function fillIdentifiers(Payment $payment, ?string $gatewayReference, ?string $transactionId): void
    {
        if ($gatewayReference !== null && $gatewayReference !== ''
            && ($payment->gateway_reference === null || $payment->gateway_reference === '')) {
            $payment->gateway_reference = $gatewayReference;
        }

        // The first id is always stored. While the payment is unpaid a newer attempt
        // may replace it (the latest attempt is the one that matters); once paid, the
        // capturing transaction is kept so later reports of other transactions
        // (failed attempts, refunds) cannot overwrite it.
        if ($transactionId !== null && $transactionId !== '' && $transactionId !== '0'
            && ($payment->gateway_transaction_id === null || $payment->gateway_transaction_id === ''
                || $payment->gateway_transaction_id === '0' || $payment->status->isUnpaid())) {
            $payment->gateway_transaction_id = $transactionId;
        }
    }

    private function assertGatewayResultMatches(Payment $payment, PaymentStatusResult $result): void
    {
        $reported = $result->merchantOrderReference;
        if ($reported !== null && $reported !== '' && !$this->belongsToPayment($payment, $reported)) {
            throw new GatewayReferenceMismatchException($payment->merchantOrderReference(), $reported);
        }

        if ($result->amount !== null && !$payment->amountMoney()->isEquivalentTo($result->amount)) {
            throw new PaymentAmountMismatchException($payment->amountMoney(), $result->amount);
        }
    }

    private function belongsToPayment(Payment $payment, string $merchantReference): bool
    {
        $reference = (string) $payment->reference;

        // Only the exact ids this payment used. Pattern matching (e.g. "<ref>-A2")
        // could claim another payment whose reference has that shape.
        return $merchantReference === $reference || $merchantReference === $payment->merchantOrderReference();
    }

    private function statusRequestFor(Payment $payment): PaymentStatusRequest
    {
        return new PaymentStatusRequest(
            paymentReference: (string) $payment->reference,
            gatewayReference: $payment->gateway_reference,
            gatewayTransactionId: $payment->gateway_transaction_id,
            gatewayOrderReference: $payment->merchantOrderReference(),
            expectedAmount: $payment->amountMoney(),
        );
    }

    private function requirePayment(string $reference): Payment
    {
        return $this->payments->findByReference($reference) ?? throw new PaymentNotFoundException($reference);
    }

    /**
     * Runs $callback in a transaction and dispatches the events it raised only
     * after it committed.
     *
     * A listener exception is logged (critical) and never thrown: the state is
     * already committed, so failing the caller (a webhook answered with 500)
     * would only make the gateway resend a notification that is now a
     * duplicate. The remaining events are still dispatched, and an unfulfilled
     * payment gets PaymentSucceeded again from payments:redispatch-unfulfilled.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function atomic(Closure $callback): mixed
    {
        try {
            $result = $this->transactions->run($callback);
        } catch (Throwable $e) {
            $this->outbox = [];

            throw $e;
        }

        $events = $this->outbox;
        $this->outbox = [];

        if ($this->events !== null) {
            foreach ($events as $event) {
                try {
                    $this->events->dispatch($event);
                } catch (Throwable $e) {
                    $this->logger->critical('payment.listener_failed', [
                        'event' => $event::class,
                        'payment_reference' => $event instanceof PaymentEvent ? $event->paymentReference : null,
                        'exception' => $e,
                    ]);
                }
            }
        }

        return $result;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e instanceof UniqueConstraintViolationException
            || in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    private function describeError(Throwable $error): string
    {
        return mb_substr((new ReflectionClass($error))->getShortName() . ': ' . $error->getMessage(), 0, 500);
    }
}
