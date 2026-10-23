<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier;

use DateTimeImmutable;
use DateTimeZone;
use Mo7mud\LaravelPaymentGateway\Contracts\DeclaresReservedMetadataKeys;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentCancellationInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentCreatorInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentGatewayInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReconciliationInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentRefundInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReturnHandlerInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentStatusProviderInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\WebhookHandlerInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
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
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventKind;
use Mo7mud\LaravelPaymentGateway\Exceptions\GatewayReferenceMismatchException;
use Mo7mud\LaravelPaymentGateway\Exceptions\UnsupportedPaymentOperationException;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierApiException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierConfigurationException;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\GatewayCapabilities;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class KashierGateway implements
    PaymentGatewayInterface,
    PaymentCreatorInterface,
    PaymentStatusProviderInterface,
    PaymentReconciliationInterface,
    PaymentCancellationInterface,
    PaymentRefundInterface,
    PaymentReturnHandlerInterface,
    WebhookHandlerInterface,
    DeclaresReservedMetadataKeys
{
    private const FORBIDDEN_METHODS = ['installment', 'installments', 'bank_installments', 'bnpl'];
    private const ORDER_PAGE_SIZE = 20;

    public function __construct(
        private readonly KashierClientInterface $client,
        private readonly KashierSignatureVerifier $signatures,
        private readonly KashierStatusMapper $statuses,
        private readonly array $config = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        // Configuration is validated when an operation needs it, so an
        // unconfigured Kashier cannot break other gateways at boot.
    }

    public function name(): GatewayName
    {
        return GatewayName::KASHIER;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            hostedCheckout: true,
            statusQuery: true,
            reconciliation: true,
            webhooks: true,
            refunds: true,
            partialRefunds: true,
            returnHandling: true,
            cancellation: true,
        );
    }

    public function reservedMetadataKeys(): array
    {
        return ['kashier_order_id', 'kashier_transaction_id', 'kashier_session_id'];
    }

    // ------------------------------------------------------------------
    // Payment sessions
    // ------------------------------------------------------------------

    public function createPayment(CreatePaymentRequest $request): PaymentCreationResult
    {
        $merchantId = $this->string('merchant_id');
        if ($merchantId === '') {
            throw new KashierConfigurationException('Kashier merchant_id is required.');
        }

        $allowedMethods = $this->string('allowed_methods', 'card,wallet');
        $methods = array_map('trim', explode(',', strtolower($allowedMethods)));
        if (array_intersect($methods, self::FORBIDDEN_METHODS) !== []) {
            throw new KashierConfigurationException('Installments and BNPL are intentionally outside this package.');
        }

        $redirect = $request->returnUrl ?: $this->string('return_url');
        if ($redirect === '') {
            throw new KashierConfigurationException('Kashier return_url is required for Payment Sessions.');
        }

        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . max(1, (int) ($this->config['expire_minutes'] ?? 30)) . ' minutes');

        $payload = [
            'expireAt' => $expiresAt->format('Y-m-d\TH:i:s.v\Z'),
            'maxFailureAttempts' => (int) ($this->config['max_failure_attempts'] ?? 3),
            'paymentType' => $this->string('payment_type', 'credit'),
            'amount' => $request->amount->toDecimal(),
            'currency' => $request->amount->currency->code,
            'order' => $request->merchantOrderReference(),
            'merchantRedirect' => $redirect,
            'display' => $this->string('display', 'en'),
            'type' => 'one-time',
            'allowedMethods' => $allowedMethods,
            'merchantId' => $merchantId,
            'failureRedirect' => (bool) ($this->config['failure_redirect'] ?? false),
            'defaultMethod' => $this->string('default_method', 'card'),
            'interactionSource' => 'ECOMMERCE',
            'enable3DS' => (bool) ($this->config['enable_3ds'] ?? true),
            // Only the payment reference and explicitly allow-listed keys leave the application.
            'metaData' => $this->forwardedMetadata($request),
        ];

        foreach (['redirect_method' => 'redirectMethod', 'description' => 'description', 'server_webhook' => 'serverWebhook'] as $key => $field) {
            $value = $this->string($key);
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        if ($request->customer !== null) {
            $customer = [];
            if ($request->customer->email !== null && $request->customer->email !== '') {
                $customer['email'] = $request->customer->email;
            }
            $customerReference = $request->customer->reference ?? $request->customer->id;
            if ($customerReference !== null && $customerReference !== '') {
                $customer['reference'] = $customerReference;
            }
            if ($customer !== []) {
                $payload['customer'] = $customer;
            }
        }

        $response = $this->client->createPaymentSession($payload);
        $sessionId = (string) ($response['_id'] ?? '');
        $sessionUrl = isset($response['sessionUrl']) ? (string) $response['sessionUrl'] : '';
        if ($sessionId === '' || $sessionUrl === '') {
            throw new KashierApiException('Kashier did not return a valid payment session.', 0, []);
        }

        $gatewayStatus = (string) ($response['status'] ?? 'CREATED');

        return new PaymentCreationResult(
            paymentReference: $request->paymentReference,
            status: $this->statuses->tryMap($gatewayStatus) ?? PaymentStatus::PENDING,
            checkoutUrl: $sessionUrl,
            gatewayReference: $sessionId,
            gatewayTransactionId: null,
            gatewayStatus: $gatewayStatus,
            expiresAt: $this->date($response['expireAt'] ?? null) ?? $expiresAt,
            amount: $request->amount,
            metadata: [
                'kashier_session_id' => $sessionId,
                'kashier_payment_params' => is_array($response['paymentParams'] ?? null) ? $response['paymentParams'] : [],
            ],
        );
    }

    public function getPaymentStatus(PaymentStatusRequest $request): PaymentStatusResult
    {
        $sessionId = $request->gatewayReference;
        if ($sessionId === null || $sessionId === '') {
            throw new KashierConfigurationException(
                'Kashier session ID (gatewayReference) is required for direct payment status queries.'
            );
        }

        $response = $this->client->getPaymentSession($sessionId);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if ($data === []) {
            throw new KashierConfigurationException(
                'Kashier returned no payment session data for session [' . $sessionId . '].'
            );
        }

        return $this->mapSessionStatusResult($request, $data);
    }

    /**
     * Abandons the checkout session, then reads it back: the session read is
     * the documented confirmation, the PATCH answer is not relied on.
     *
     * A failed abandon does not stop the cancellation (E-K4: Kashier test
     * rejects the documented call with "merchant not found"). The session read
     * still decides: a paid session stays paid, an open one is then cancelled
     * locally, and a later capture is a late payment.
     */
    public function cancelPayment(PaymentStatusRequest $request): PaymentStatusResult
    {
        $sessionId = $request->gatewayReference;
        if ($sessionId === null || $sessionId === '') {
            throw new KashierConfigurationException('Kashier session ID (gatewayReference) is required to cancel a payment.');
        }

        if (!$this->client instanceof KashierSessionCancellationInterface) {
            throw new UnsupportedPaymentOperationException('kashier', 'cancel_payment');
        }

        try {
            $this->client->abandonPaymentSession($sessionId);
        } catch (KashierApiException $e) {
            $this->logger->warning('payment.kashier_abandon_failed', [
                'gateway' => 'kashier',
                'payment_reference' => $request->paymentReference,
                'session_id' => $sessionId,
                'status_code' => $e->statusCode,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->getPaymentStatus($request);
    }

    public function reconcilePayment(PaymentStatusRequest $request): PaymentStatusResult
    {
        $order = $this->selectBestOrder($this->fetchOrders($request->merchantOrderReference()), $request);

        if ($order !== null) {
            return $this->keepOpenWhileSessionIsActive($request, $this->mapOrderStatusResult($request, $order));
        }

        // No order yet (the customer never attempted payment): the session is the source of truth.
        if ($request->gatewayReference !== null && $request->gatewayReference !== '') {
            return $this->getPaymentStatus($request);
        }

        return new PaymentStatusResult(
            paymentReference: $request->paymentReference,
            status: null,
            metadata: ['kashier_order_found' => false],
        );
    }

    /** @return list<array<string, mixed>> */
    private function fetchOrders(string $merchantOrderId): array
    {
        if (!$this->client instanceof KashierPaginatedOrdersInterface) {
            return $this->ordersFrom($this->client->getOrderStatus($merchantOrderId));
        }

        // Page 1 uses the documented defaults. Further pages are best effort:
        // Kashier does not document paging for this search, so a rejected page
        // ends the scan instead of failing the reconciliation.
        $orders = $this->ordersFrom($this->client->getOrderStatus($merchantOrderId));
        $maxPages = max(1, (int) ($this->config['reconcile_max_pages'] ?? 5));
        $batchSize = count($orders);

        for ($page = 2; $page <= $maxPages && $batchSize >= self::ORDER_PAGE_SIZE; $page++) {
            try {
                $batch = $this->ordersFrom($this->client->getOrdersPage($merchantOrderId, $page, self::ORDER_PAGE_SIZE));
            } catch (KashierApiException) {
                break;
            }

            $orders = array_merge($orders, $batch);
            $batchSize = count($batch);
        }

        return $orders;
    }

    /** @return list<array<string, mixed>> */
    private function ordersFrom(array $response): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * "search" is a partial match, so only exact merchantOrderId matches are
     * considered. Among them: stored session, stored transaction, a successful
     * capture, then the most recently modified order.
     */
    private function selectBestOrder(array $orders, PaymentStatusRequest $request): ?array
    {
        $expected = $request->merchantOrderReference();
        $exact = array_values(array_filter(
            $orders,
            static fn (array $order): bool => (string) ($order['merchantOrderId'] ?? '') === $expected,
        ));

        if ($exact === []) {
            return null;
        }

        if ($request->gatewayReference !== null && $request->gatewayReference !== '') {
            foreach ($exact as $order) {
                if ((string) ($order['sessionId'] ?? '') === $request->gatewayReference) {
                    return $order;
                }
            }
        }

        if ($request->gatewayTransactionId !== null && $request->gatewayTransactionId !== '') {
            foreach ($exact as $order) {
                if ($this->containsTransaction($order['transactions'] ?? [], $request->gatewayTransactionId)) {
                    return $order;
                }
            }
        }

        foreach ($exact as $order) {
            if ($this->successfulCapture($order['transactions'] ?? []) !== null) {
                return $order;
            }
        }

        usort($exact, fn (array $a, array $b): int => $this->orderTime($b) <=> $this->orderTime($a));

        return $exact[0];
    }

    /**
     * A declined attempt shows up as an order with status FAILURE, but the
     * customer can retry on the same session until it expires or runs out of
     * attempts. Only the session says whether the payment is really over, so
     * a failed order never closes the payment on its own.
     */
    private function keepOpenWhileSessionIsActive(PaymentStatusRequest $request, PaymentStatusResult $fromOrder): PaymentStatusResult
    {
        if ($fromOrder->status !== PaymentStatus::FAILED) {
            return $fromOrder;
        }

        if ($request->gatewayReference === null || $request->gatewayReference === '') {
            // No session to ask: leave the stored status alone; expiry closes it.
            return $this->withStatus($fromOrder, null);
        }

        try {
            $session = $this->getPaymentStatus($request);
        } catch (KashierApiException|KashierConfigurationException) {
            return $this->withStatus($fromOrder, null);
        }

        if ($session->status === null || ($session->status->isUnpaid() && !$session->status->isClosedUnpaid())) {
            // Session still open (or unknown status): the attempt failed, the payment did not.
            return $this->withStatus($fromOrder, null);
        }

        if ($session->status->isClosedUnpaid()) {
            return $this->withStatus($fromOrder, $session->status);
        }

        // The session reports a payment the failed order does not show: trust the session,
        // keeping the order identifiers needed for refunds.
        return new PaymentStatusResult(
            paymentReference: $session->paymentReference,
            status: $session->status,
            amount: $session->amount,
            paidAmount: $session->paidAmount,
            refundedAmount: $session->refundedAmount,
            gatewayReference: $session->gatewayReference,
            gatewayTransactionId: $session->gatewayTransactionId,
            gatewayStatus: $session->gatewayStatus,
            occurredAt: $session->occurredAt,
            metadata: array_replace(
                $fromOrder->metadata,
                array_filter($session->metadata, static fn (mixed $value): bool => $value !== null),
            ),
            merchantOrderReference: $session->merchantOrderReference,
        );
    }

    private function withStatus(PaymentStatusResult $result, ?PaymentStatus $status): PaymentStatusResult
    {
        return new PaymentStatusResult(
            paymentReference: $result->paymentReference,
            status: $status,
            amount: $result->amount,
            paidAmount: $result->paidAmount,
            refundedAmount: $result->refundedAmount,
            gatewayReference: $result->gatewayReference,
            gatewayTransactionId: $result->gatewayTransactionId,
            gatewayStatus: $result->gatewayStatus,
            occurredAt: $result->occurredAt,
            metadata: $result->metadata,
            merchantOrderReference: $result->merchantOrderReference,
        );
    }

    private function mapSessionStatusResult(PaymentStatusRequest $request, array $data): PaymentStatusResult
    {
        $gatewayStatus = strtoupper((string) ($data['status'] ?? ''));
        if ($gatewayStatus === '') {
            throw new KashierConfigurationException('Kashier payment session response does not contain a status.');
        }

        $merchantOrderId = $this->nullable($data['merchantOrderId'] ?? null);
        $this->assertMerchantOrder($request, $merchantOrderId);

        $currency = Currency::fromIso((string) ($data['currency'] ?? 'EGP'));
        $amount = $this->money($data['amount'] ?? null, $currency);
        $paidAmount = $this->money($data['capturedAmount'] ?? null, $currency);
        $refundedAmount = $this->money($data['refundedAmount'] ?? null, $currency);

        $history = is_array($data['history'] ?? null) ? $data['history'] : [];
        $transaction = $this->successfulCapture($history) ?? $this->lastTransaction($history);
        $transactionId = $this->nullable($data['targetTransactionId'] ?? null) ?? $this->nullable($transaction['transactionId'] ?? null);

        return new PaymentStatusResult(
            paymentReference: $request->paymentReference,
            status: $this->withRefunds($this->statuses->tryMap($gatewayStatus), $amount, $refundedAmount),
            amount: $amount,
            paidAmount: $paidAmount,
            refundedAmount: $refundedAmount,
            gatewayReference: $this->nullable($data['sessionId'] ?? null) ?? $request->gatewayReference,
            gatewayTransactionId: $transactionId ?? $request->gatewayTransactionId,
            gatewayStatus: $gatewayStatus,
            occurredAt: $this->date($data['updatedAt'] ?? $transaction['date'] ?? $data['createdAt'] ?? null),
            metadata: [
                'kashier_session_id' => $this->nullable($data['sessionId'] ?? null) ?? $request->gatewayReference,
                'kashier_order_id' => $this->nullable($data['orderId'] ?? null),
                'kashier_method' => $this->scalar($data['method'] ?? null),
                'kashier_payment_channel' => $this->scalar($data['paymentChannel'] ?? null),
            ],
            merchantOrderReference: $merchantOrderId,
        );
    }

    private function mapOrderStatusResult(PaymentStatusRequest $request, array $data): PaymentStatusResult
    {
        $gatewayStatus = strtoupper((string) ($data['status'] ?? ''));
        if ($gatewayStatus === '') {
            throw new KashierConfigurationException('Kashier order response does not contain a status.');
        }

        $order = is_array($data['order'] ?? null) ? $data['order'] : [];
        $currency = Currency::fromIso((string) ($order['currency'] ?? $data['currency'] ?? 'EGP'));
        $amount = $this->money($order['amount'] ?? $data['originalAmount'] ?? null, $currency);
        $paidAmount = $this->money($data['totalCapturedAmount'] ?? null, $currency);
        $refundedAmount = $this->money($data['totalRefundedAmount'] ?? null, $currency);

        $transactions = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];
        $transaction = $this->successfulCapture($transactions) ?? $this->lastTransaction($transactions);
        $orderId = $this->nullable($data['orderId'] ?? null);
        $sessionId = $this->nullable($data['sessionId'] ?? null);

        return new PaymentStatusResult(
            paymentReference: $request->paymentReference,
            status: $this->withRefunds($this->statuses->tryMap($gatewayStatus), $amount, $refundedAmount),
            amount: $amount,
            paidAmount: $paidAmount,
            refundedAmount: $refundedAmount,
            gatewayReference: $sessionId ?? $request->gatewayReference,
            gatewayTransactionId: $this->nullable($transaction['transactionId'] ?? null) ?? $request->gatewayTransactionId,
            gatewayStatus: $gatewayStatus,
            occurredAt: $this->date($transaction['responseDate'] ?? $data['lastModifiedDate'] ?? $data['date'] ?? null),
            metadata: [
                'kashier_session_id' => $sessionId,
                'kashier_order_id' => $orderId,
                'kashier_order_reference' => $this->scalar($data['orderReference'] ?? null),
                'kashier_method' => $this->scalar($data['method'] ?? null),
                'kashier_payment_channel' => $this->scalar($data['paymentChannel'] ?? null),
                'kashier_total_captured_amount' => $this->scalar($data['totalCapturedAmount'] ?? null),
                'kashier_total_refunded_amount' => $this->scalar($data['totalRefundedAmount'] ?? null),
                'kashier_reconciliation_status' => $this->scalar($transaction['reconcilation'] ?? null),
                'kashier_merchant_webhook_reconciliation' => $this->scalar($transaction['merchantWebhookReconciliation'] ?? null),
                'kashier_is_currently_reconciled' => $this->scalar($transaction['isCurrentlyReconciled'] ?? null),
            ],
            merchantOrderReference: $this->nullable($data['merchantOrderId'] ?? null),
        );
    }

    // ------------------------------------------------------------------
    // Refunds
    // ------------------------------------------------------------------

    public function refund(RefundPaymentRequest $request): RefundResult
    {
        $orderId = $this->nullable($request->metadata['kashier_order_id'] ?? null);

        if ($orderId === null) {
            $sessionId = $this->nullable($request->metadata['kashier_session_id'] ?? null)
                ?? $this->nullable($request->metadata['gateway_reference'] ?? null);

            if ($sessionId !== null) {
                $status = $this->getPaymentStatus(new PaymentStatusRequest(
                    paymentReference: $request->paymentReference,
                    gatewayReference: $sessionId,
                    gatewayOrderReference: $this->nullable($request->metadata['gateway_order_reference'] ?? null),
                ));
                $orderId = $this->nullable($status->metadata['kashier_order_id'] ?? null);
            }
        }

        if ($orderId === null) {
            throw new KashierConfigurationException('Kashier order ID is required to refund a payment.');
        }

        $response = $this->client->refund($orderId, $request->amount?->toDecimal(), $request->reason);
        $body = is_array($response['response'] ?? null) ? $response['response'] : [];
        $gatewayStatus = strtoupper((string) ($body['status'] ?? $response['status'] ?? ''));

        $status = match ($gatewayStatus) {
            'SUCCESS' => RefundStatus::SUCCEEDED,
            'PENDING' => RefundStatus::PENDING,
            default => RefundStatus::FAILED,
        };

        $currency = Currency::fromIso((string) ($body['currency'] ?? $request->amount?->currency->code ?? 'EGP'));
        $amount = $this->money($body['amount'] ?? null, $currency) ?? $request->amount;

        return new RefundResult(
            paymentReference: $request->paymentReference,
            status: $status,
            amount: $amount,
            refundReference: $this->nullable($body['transactionId'] ?? null),
            gatewayStatus: $gatewayStatus !== '' ? $gatewayStatus : null,
            occurredAt: $this->date($response['creationDate'] ?? $body['creationDate'] ?? null),
            metadata: [
                'kashier_order_id' => $orderId,
                'kashier_refund_transaction_id' => $this->nullable($body['transactionId'] ?? null),
                'kashier_refund_response_code' => $this->scalar($body['transactionResponseCode'] ?? null),
                'kashier_total_refunded_amount' => $this->scalar($body['totalRefundedAmount'] ?? null),
            ],
            totalRefunded: $this->money($body['totalRefundedAmount'] ?? null, $currency),
        );
    }

    // ------------------------------------------------------------------
    // Redirect and webhooks
    // ------------------------------------------------------------------

    public function handleReturn(PaymentReturnRequest $request): PaymentReturnResult
    {
        $this->signatures->verifyRedirect($request->query);

        $query = $request->query;
        $gatewayStatus = strtoupper((string) ($query['paymentStatus'] ?? 'PENDING'));

        return new PaymentReturnResult(
            paymentReference: (string) ($query['merchantOrderId'] ?? ''),
            observedStatus: $this->statuses->tryMap($gatewayStatus) ?? PaymentStatus::PENDING,
            gatewayReference: null,
            gatewayTransactionId: $this->nullable($query['transactionId'] ?? null),
            gatewayStatus: $gatewayStatus,
            occurredAt: null,
            metadata: [
                'return_only' => true,
                'kashier_order_id' => $this->nullable($query['orderId'] ?? null),
            ],
            rawQuery: array_intersect_key($query, array_flip(['paymentStatus', 'merchantOrderId', 'orderId', 'orderReference', 'transactionId', 'amount', 'currency', 'mode'])),
            signatureVerified: true,
        );
    }

    public function handleWebhook(WebhookRequest $request): WebhookResult
    {
        // Decision values come from the signed subset. The top-level "event" is
        // outside Kashier's signature unless it is listed in signatureKeys, so an
        // unsigned event only selects how the claim is checked: payments and
        // refunds are confirmed with the API, and nothing unverifiable is applied.
        $signed = $this->signatures->verifyWebhook($request->payload, $request->headers);

        foreach (KashierSignatureVerifier::DEFAULT_REQUIRED_KEYS as $required) {
            if (!isset($signed[$required]) || !is_scalar($signed[$required]) || (string) $signed[$required] === '') {
                throw new WebhookVerificationException(sprintf('Kashier webhook does not sign required field [%s].', $required));
            }
        }

        // When the merchant id is signed it must be ours (one API key can be
        // shared across merchants in some account setups).
        $configuredMid = $this->string('merchant_id');
        if (isset($signed['merchantId']) && is_scalar($signed['merchantId']) && $configuredMid !== ''
            && (string) $signed['merchantId'] !== $configuredMid) {
            throw new WebhookVerificationException('Kashier webhook belongs to another merchant.');
        }

        $eventSigned = isset($signed['event']) && is_scalar($signed['event']) && (string) $signed['event'] !== '';
        $event = strtolower(trim((string) ($eventSigned ? $signed['event'] : (is_scalar($request->payload['event'] ?? null) ? $request->payload['event'] : ''))));
        $gatewayStatus = strtoupper(trim((string) $signed['status']));
        $transactionId = (string) $signed['transactionId'];
        $merchantOrderId = (string) $signed['merchantOrderId'];

        if (!is_scalar($signed['amount']) || !is_numeric((string) $signed['amount'])) {
            throw new WebhookVerificationException('Kashier webhook amount is not numeric.');
        }
        $amount = Money::fromGatewayAmount((string) $signed['amount'], Currency::fromIso((string) $signed['currency']));

        [$kind, $status, $refund, $ignore] = $this->classify($event, $gatewayStatus, $amount, $transactionId, $eventSigned);

        return new WebhookResult(
            eventId: 'kashier:' . ($event !== '' ? $event : 'unknown') . ':' . $transactionId . ':' . $gatewayStatus,
            eventType: $event !== '' ? $event : 'unknown',
            paymentReference: $merchantOrderId,
            status: $status,
            amount: $amount,
            gatewayReference: null,
            gatewayTransactionId: $kind === WebhookEventKind::REFUND ? null : $transactionId,
            gatewayStatus: $gatewayStatus,
            occurredAt: $this->date($request->payload['data']['creationDate'] ?? null),
            metadata: array_filter([
                'kashier_order_id' => $this->nullable($signed['kashierOrderId'] ?? null),
                'failure_reason' => $kind === WebhookEventKind::ATTEMPT_FAILED
                    ? $this->nullable($signed['transactionResponseCode'] ?? null)
                    : null,
                'kashier_event_signed' => $eventSigned,
            ], static fn (mixed $value): bool => $value !== null),
            rawPayload: $request->payload,
            kind: $kind,
            refund: $refund,
            requiresVerification: $kind === WebhookEventKind::PAYMENT || $kind === WebhookEventKind::REFUND,
            referenceTrusted: true,
            ignoreReason: $ignore,
        );
    }

    /**
     * Maps Kashier (event, data.status) pairs. data.status alone is not enough:
     * a SUCCESS void, reversal or refund is not a successful payment.
     *
     * @return array{0: WebhookEventKind, 1: ?PaymentStatus, 2: ?RefundNotification, 3: ?string}
     */
    private function classify(string $event, string $status, Money $amount, string $transactionId, bool $eventSigned = true): array
    {
        if (!in_array($status, ['SUCCESS', 'FAILURE', 'PENDING'], true)) {
            return [WebhookEventKind::IGNORED, null, null, 'unsupported status ' . $status];
        }

        switch ($event) {
            case 'pay':
            case 'capture':
            case 'idempotency':
                return match ($status) {
                    'SUCCESS' => [WebhookEventKind::PAYMENT, PaymentStatus::SUCCEEDED, null, null],
                    'PENDING' => [WebhookEventKind::PAYMENT, PaymentStatus::PENDING, null, null],
                    default => [WebhookEventKind::ATTEMPT_FAILED, null, null, null],
                };

            case 'authorize':
                return match ($status) {
                    'SUCCESS' => [WebhookEventKind::PAYMENT, PaymentStatus::PROCESSING, null, null],
                    'PENDING' => [WebhookEventKind::PAYMENT, PaymentStatus::PENDING, null, null],
                    default => [WebhookEventKind::ATTEMPT_FAILED, null, null, null],
                };

            case 'reject':
                if ($status !== 'SUCCESS') {
                    return [WebhookEventKind::IGNORED, null, null, 'reject ' . $status];
                }

                // An attempt failure is not confirmed with the API. With an unsigned
                // event, a signed "pay SUCCESS" could be replayed as "reject", so
                // only a signed event may raise PaymentAttemptFailed.
                return $eventSigned
                    ? [WebhookEventKind::ATTEMPT_FAILED, null, null, null]
                    : [WebhookEventKind::IGNORED, null, null, 'unsigned reject event'];

            case 'refund':
            case 'partial_refund':
            case 'void':
            case 'reversal':
                $type = match ($event) {
                    'void' => RefundNotification::TYPE_VOID,
                    'reversal' => RefundNotification::TYPE_REVERSAL,
                    default => RefundNotification::TYPE_REFUND,
                };
                $refundStatus = match ($status) {
                    'SUCCESS' => RefundStatus::SUCCEEDED,
                    'PENDING' => RefundStatus::PENDING,
                    default => RefundStatus::FAILED,
                };

                return [WebhookEventKind::REFUND, null, new RefundNotification($amount, $refundStatus, $transactionId, $type), null];

            default:
                return [WebhookEventKind::IGNORED, null, null, 'unsupported event ' . ($event !== '' ? $event : '(none)')];
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function forwardedMetadata(CreatePaymentRequest $request): array
    {
        $allowed = $this->config['forward_metadata_keys'] ?? [];
        $forwarded = ['payment_reference' => $request->paymentReference];

        foreach (is_array($allowed) ? $allowed : [] as $key) {
            if (is_string($key) && array_key_exists($key, $request->metadata) && is_scalar($request->metadata[$key])) {
                $forwarded[$key] = $request->metadata[$key];
            }
        }

        return $forwarded;
    }

    private function assertMerchantOrder(PaymentStatusRequest $request, ?string $merchantOrderId): void
    {
        if ($merchantOrderId === null) {
            return;
        }

        $expected = $request->merchantOrderReference();
        if ($merchantOrderId === $expected || $merchantOrderId === $request->paymentReference) {
            return;
        }

        throw new GatewayReferenceMismatchException($expected, $merchantOrderId);
    }

    private function withRefunds(?PaymentStatus $status, ?Money $amount, ?Money $refunded): ?PaymentStatus
    {
        if ($amount === null || $refunded === null || $refunded->minorAmount <= 0) {
            return $status;
        }

        return $refunded->minorAmount >= $amount->minorAmount
            ? PaymentStatus::REFUNDED
            : PaymentStatus::PARTIALLY_REFUNDED;
    }

    private function successfulCapture(mixed $transactions): ?array
    {
        if (!is_array($transactions)) {
            return null;
        }

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $operation = strtolower((string) ($transaction['operation'] ?? ''));
            if (in_array($operation, ['pay', 'capture'], true) && strtoupper((string) ($transaction['status'] ?? '')) === 'SUCCESS') {
                return $transaction;
            }
        }

        return null;
    }

    private function lastTransaction(mixed $transactions): array
    {
        if (!is_array($transactions)) {
            return [];
        }

        $last = [];
        foreach ($transactions as $transaction) {
            if (is_array($transaction)) {
                $last = $transaction;
            }
        }

        return $last;
    }

    private function containsTransaction(mixed $transactions, string $transactionId): bool
    {
        if (!is_array($transactions)) {
            return false;
        }

        foreach ($transactions as $transaction) {
            if (is_array($transaction) && (string) ($transaction['transactionId'] ?? '') === $transactionId) {
                return true;
            }
        }

        return false;
    }

    private function orderTime(array $order): int
    {
        return $this->date($order['lastModifiedDate'] ?? $order['date'] ?? $order['creationDate'] ?? null)?->getTimestamp() ?? 0;
    }

    private function money(mixed $value, Currency $currency): ?Money
    {
        if ($value === null || $value === '' || !is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }

        return Money::fromGatewayAmount(is_string($value) ? $value : (is_int($value) ? $value : (float) $value), $currency);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'NA' || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function string(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }
}
