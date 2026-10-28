<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;
use Mo7mud\LaravelPaymentGateway\Contracts\DeclaresReservedMetadataKeys;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentCreatorInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentGatewayInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReconciliationInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentRefundInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentReturnHandlerInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentStatusProviderInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\RefundListingInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\WebhookHandlerInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\GatewayRefundList;
use Mo7mud\LaravelPaymentGateway\DTOs\GatewayRefundRecord;
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
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Exceptions\InvalidRefundException;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\Exceptions\FawaterkConfigurationException;
use Mo7mud\LaravelPaymentGateway\Support\DecimalNumber;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\GatewayCapabilities;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

final class FawaterkGateway implements DeclaresReservedMetadataKeys, PaymentCreatorInterface, PaymentGatewayInterface, PaymentReconciliationInterface, PaymentRefundInterface, PaymentReturnHandlerInterface, PaymentStatusProviderInterface, RefundListingInterface, WebhookHandlerInterface
{
    /** Default mapping of Fawaterk refund request statuses (case-insensitive). */
    private const REFUND_STATUS_MAP = [
        'approved' => 'succeeded', 'accepted' => 'succeeded', 'completed' => 'succeeded',
        'refunded' => 'succeeded', 'success' => 'succeeded', 'done' => 'succeeded', 'paid' => 'succeeded',
        'rejected' => 'failed', 'declined' => 'failed', 'refused' => 'failed', 'failed' => 'failed',
        'cancelled' => 'failed', 'canceled' => 'failed', 'deleted' => 'failed',
    ];

    private const WEBHOOK_TYPES = [
        'paid' => 'paid',
        'pending' => 'pending',
        'failed' => 'failed',
        'cancel' => 'cancelled',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'refund' => 'refund',
    ];

    /**
     * @param  array<mixed>  $config
     */
    public function __construct(
        private readonly FawaterkClientInterface $client,
        private readonly FawaterkMapper $mapper,
        private readonly FawaterkSignatureVerifier $signatures,
        private readonly array $config = [],
        private readonly ?CacheRepository $cache = null,
    ) {}

    public function reservedMetadataKeys(): array
    {
        return ['fawaterk_transaction_id', 'fawaterk_intent_key'];
    }

    public function name(): GatewayName
    {
        return GatewayName::FAWATERK;
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
        );
    }

    public function createPayment(CreatePaymentRequest $request): PaymentCreationResult
    {
        $payload = $this->buildTransactionPayload($request);
        $response = $this->client->createTransaction($payload);

        return $this->mapper->creationResult($request, $response);
    }

    public function getPaymentStatus(PaymentStatusRequest $request): PaymentStatusResult
    {
        return $this->queryStatus($request);
    }

    public function reconcilePayment(PaymentStatusRequest $request): PaymentStatusResult
    {
        return $this->queryStatus($request);
    }

    public function handleReturn(PaymentReturnRequest $request): PaymentReturnResult
    {
        $query = $request->query;
        $payLoad = $this->mapper->decodePayLoad($query['pay_load'] ?? null);
        $paymentReference = (string) (
            $payLoad['payment_reference']
            ?? $query['payment_reference']
            ?? $query['paymentReference']
            ?? $query['merchantOrderId']
            ?? ''
        );

        $gatewayStatus = (string) ($query['status'] ?? $query['payment_status'] ?? $query['paymentStatus'] ?? 'pending');

        return new PaymentReturnResult(
            paymentReference: $paymentReference,
            observedStatus: $this->mapper->mapStatus($gatewayStatus),
            gatewayReference: isset($query['intent_key']) ? (string) $query['intent_key'] : (isset($query['transaction_key']) ? (string) $query['transaction_key'] : null),
            gatewayTransactionId: isset($query['transaction_id']) ? (string) $query['transaction_id'] : (isset($query['transactionId']) ? (string) $query['transactionId'] : null),
            gatewayStatus: $gatewayStatus,
            occurredAt: isset($query['paidAt']) ? $this->parseDate((string) $query['paidAt']) : null,
            metadata: ['return_only' => true],
            rawQuery: $query,
        );
    }

    public function handleWebhook(WebhookRequest $request): WebhookResult
    {
        $payload = $request->payload;
        $eventType = $this->webhookType($request);

        match ($eventType) {
            'paid', 'pending' => $this->signatures->verifyPaidOrPending($payload),
            'failed' => $this->signatures->verifyFailed($payload),
            'cancelled' => $this->signatures->verifyCancel($payload),
            'refund' => $this->signatures->verifyRefund($payload),
            default => throw new WebhookVerificationException('Unsupported Fawaterk webhook type.'),
        };

        return $this->mapper->webhookResult($eventType, $payload);
    }

    /**
     * Fawaterk sends each notification type to its own dashboard URL, so the
     * route type is authoritative. Payload sniffing is kept only for the
     * legacy single-URL setup.
     */
    private function webhookType(WebhookRequest $request): string
    {
        $payload = $request->payload;
        $routeType = $request->type !== null ? strtolower(trim($request->type)) : '';
        // Fawaterk sends application/json only when the URL path contains "_json".
        $routeType = (string) preg_replace('/_?json$/', '', $routeType);

        if ($routeType !== '') {
            $type = self::WEBHOOK_TYPES[$routeType] ?? throw new WebhookVerificationException('Unsupported Fawaterk webhook type.');

            if ($type === 'paid' || $type === 'pending') {
                return strtolower((string) ($payload['status'] ?? '')) === 'paid' ? 'paid' : 'pending';
            }

            return $type;
        }

        return $this->detectWebhookType($payload);
    }

    public function refund(RefundPaymentRequest $request): RefundResult
    {
        $transactionId = $request->metadata['gateway_transaction_id']
            ?? $request->metadata['fawaterk_transaction_id']
            ?? null;
        if (! is_numeric($transactionId) || (int) $transactionId <= 0) {
            throw new FawaterkConfigurationException('Fawaterk transaction_id is required to create a refund.');
        }

        $amount = $request->amount;
        if ($amount === null) {
            throw new InvalidRefundException('Fawaterk refunds require an explicit amount.');
        }
        if ($amount->minorAmount < 10 ** $amount->currency->fractionDigits) {
            throw new InvalidRefundException('Fawaterk requires a refund amount of at least 1.');
        }

        $language = isset($request->metadata['fawaterk_refund_lang']) ? (string) $request->metadata['fawaterk_refund_lang'] : null;
        [$reason, $unmatchedReason] = $this->resolveRefundReason($language, $request->reason);

        $payload = [
            'refund_type' => '3',
            'refund_id' => (int) $transactionId,
            'reason' => $reason,
            // The schema requires a JSON number; DecimalNumber keeps it exact.
            'refundable_amount' => DecimalNumber::fromMoney($amount),
        ];

        $comment = trim((string) ($request->metadata['fawaterk_comment'] ?? ''));
        if ($unmatchedReason !== null) {
            $comment = trim($unmatchedReason.($comment !== '' ? ' - '.$comment : ''));
        }
        if ($comment !== '') {
            $payload['comment'] = mb_substr($comment, 0, 500);
        }

        $response = $this->client->createRefund($payload);
        $status = (string) ($response['status'] ?? '');

        // Fawaterk refunds go through an approval flow: a successful submission
        // is PENDING until the (signed) refund webhook arrives.
        return new RefundResult(
            paymentReference: $request->paymentReference,
            status: strtolower($status) === 'success' ? RefundStatus::PENDING : RefundStatus::FAILED,
            amount: $amount,
            refundReference: null,
            gatewayStatus: $status !== '' ? $status : null,
            occurredAt: null,
            metadata: [
                'fawaterk_transaction_id' => (string) $transactionId,
                'fawaterk_refund_type' => '3',
                'fawaterk_refund_reason' => $reason,
            ],
        );
    }

    private function queryStatus(PaymentStatusRequest $request): PaymentStatusResult
    {
        $intentKey = $request->gatewayReference;
        if ($intentKey === null || $intentKey === '') {
            throw new FawaterkConfigurationException('Fawaterk intent_key is required for status lookup.');
        }

        $response = $this->client->getTransactionData($intentKey);

        return $this->mapper->statusResult($request->paymentReference, $response, $request->expectedAmount);
    }

    /**
     * @return array<mixed>
     */
    private function buildTransactionPayload(CreatePaymentRequest $request): array
    {
        $customer = $this->mapCustomer($request);
        $cartItems = $this->cartItems($request);
        $redirectionUrls = $this->redirectionUrls($request);

        if (isset($redirectionUrls['webhookUrl']) && ! str_contains((string) (parse_url((string) $redirectionUrls['webhookUrl'], PHP_URL_PATH) ?? ''), '_json')) {
            throw new FawaterkConfigurationException('Fawaterk webhookUrl must contain `_json` so the paid/pending webhook is delivered as application/json.');
        }

        $payload = [
            'cartTotal' => DecimalNumber::fromMoney($request->amount),
            'currency' => $request->amount->currency->code,
            'customer' => $customer,
            'cartItems' => $cartItems,
            // Only the payment reference and explicitly allow-listed keys leave the application.
            'pay_load' => $this->forwardedMetadata($request),
            'tr_number' => (string) ($request->metadata['fawaterk_tr_number'] ?? $request->merchantOrderReference()),
        ];

        if ($redirectionUrls !== []) {
            $payload['redirectionUrls'] = $redirectionUrls;
        }

        $optional = [
            'payment_method_id' => 'fawaterk_payment_method_id',
            'mobileWalletNumber' => 'fawaterk_mobile_wallet_number',
            'save_customer' => 'fawaterk_save_customer',
            'sendEmail' => 'fawaterk_send_email',
            'sendSMS' => 'fawaterk_send_sms',
            'due_date' => 'fawaterk_due_date',
            'redirectOption' => 'fawaterk_redirect_option',
            'authAndCapture' => 'fawaterk_auth_and_capture',
            'list_style' => 'fawaterk_list_style',
            'lang' => 'fawaterk_lang',
        ];

        foreach ($optional as $apiField => $metadataKey) {
            if (! array_key_exists($metadataKey, $request->metadata) || $request->metadata[$metadataKey] === null || $request->metadata[$metadataKey] === '') {
                continue;
            }

            $value = $request->metadata[$metadataKey];
            $payload[$apiField] = match ($apiField) {
                'payment_method_id' => ! is_numeric($value) ? throw new InvalidArgumentException('Fawaterk payment_method_id must be numeric.') : (int) $value,
                'save_customer', 'sendEmail', 'sendSMS', 'redirectOption' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new InvalidArgumentException("Fawaterk {$apiField} must be boolean."),
                'authAndCapture' => ! is_numeric($value) ? throw new InvalidArgumentException('Fawaterk authAndCapture must be numeric.') : (int) $value,
                default => $value,
            };
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private function mapCustomer(CreatePaymentRequest $request): array
    {
        $customer = $request->customer;
        $name = trim((string) ($customer->name ?? ''));

        if ($name === '') {
            $reference = trim((string) ($customer->reference ?? $customer->id ?? ''));
            if ($reference === '') {
                throw new FawaterkConfigurationException('Fawaterk customer first_name and last_name require CustomerData::name or a customer reference.');
            }
            $name = $reference;
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = (string) ($parts[0] ?? 'Customer');
        $lastName = count($parts) > 1
            ? implode(' ', array_slice($parts, 1))
            : (string) ($customer->reference ?? 'Customer');

        $result = [
            'first_name' => $firstName,
            'last_name' => $lastName !== '' ? $lastName : 'Customer',
        ];

        if ($customer?->email !== null && $customer->email !== '') {
            $result['email'] = $customer->email;
        }
        if ($customer?->phone !== null && $customer->phone !== '') {
            $result['phone'] = $customer->phone;
        }
        if ($customer?->id !== null && $customer->id !== '') {
            $result['customer_unique_id'] = $customer->id;
        }
        if ($customer?->reference !== null && $customer->reference !== '') {
            $result['customer_number'] = $customer->reference;
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    private function cartItems(CreatePaymentRequest $request): array
    {
        $items = $request->metadata['cart_items'] ?? null;
        if (! is_array($items) || $items === []) {
            return [[
                'name' => (string) ($request->metadata['cart_item_name'] ?? 'Payment'),
                'price' => DecimalNumber::fromMoney($request->amount),
                'quantity' => 1,
            ]];
        }

        $mapped = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $price = $item['price'] ?? null;
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($name === '' || ! (is_string($price) || is_int($price) || is_float($price)) || $quantity === false) {
                throw new InvalidArgumentException('Each Fawaterk cart item requires name, price, and an integer quantity >= 1.');
            }

            // Strict parse: a price with more decimals than the currency allows is rejected, not rounded.
            $priceMoney = Money::fromDecimal(is_string($price) ? $price : (string) $price, $request->amount->currency);

            $mapped[] = [
                'name' => $name,
                'price' => DecimalNumber::fromMoney($priceMoney),
                'quantity' => $quantity,
                '_minor' => $priceMoney->minorAmount,
            ];
        }

        if ($mapped === []) {
            throw new InvalidArgumentException('Fawaterk cart_items metadata did not contain any valid items.');
        }

        $sumMinor = 0;
        foreach ($mapped as $index => $item) {
            if ($item['_minor'] > intdiv(Money::MAX_MINOR - $sumMinor, $item['quantity'])) {
                throw new InvalidArgumentException('Fawaterk cart_items total is out of range.');
            }
            $sumMinor += $item['_minor'] * $item['quantity'];
            unset($mapped[$index]['_minor']);
        }
        if ($sumMinor !== $request->amount->minorAmount) {
            throw new InvalidArgumentException('Fawaterk cart_items total must equal the payment amount.');
        }

        return $mapped;
    }

    /**
     * @return array<mixed>
     */
    private function redirectionUrls(CreatePaymentRequest $request): array
    {
        return array_filter([
            'successUrl' => $request->returnUrl ?: ($this->config['success_url'] ?? null),
            'failUrl' => $this->config['fail_url'] ?? null,
            'pendingUrl' => $this->config['pending_url'] ?? null,
            'backUrl' => $request->cancelUrl ?: ($this->config['back_url'] ?? null),
            'webhookUrl' => $this->config['webhook_url'] ?? null,
        ], static fn ($value): bool => is_string($value) && trim($value) !== '');
    }

    /**
     * Refunds of this payment from POST /api/v3/refund/index (vendor-wide,
     * 10 per page). Only items for this payment's transaction are returned.
     */
    public function listRefunds(PaymentStatusRequest $request): GatewayRefundList
    {
        $transactionId = $request->gatewayTransactionId;
        if ($transactionId === null || preg_match('/^[1-9]\d*$/', $transactionId) !== 1) {
            return new GatewayRefundList([], false);
        }

        $currency = $request->expectedAmount->currency ?? Currency::egp();
        $pager = $this->client instanceof FawaterkRefundPagesInterface ? $this->client : null;
        $maxPages = max(1, (int) ($this->config['refund_list_max_pages'] ?? 20));

        $first = $this->refundPage($pager !== null ? $pager->listRefundsPage(1) : $this->client->listRefunds());
        $lastPage = $pager !== null ? max(1, $first['last_page']) : 1;

        // Read newest pages first: a refund being confirmed is usually recent.
        $pages = $lastPage > 1 && $this->oldestFirst($first['items'])
            ? range($lastPage, 2)
            : ($lastPage > 1 ? range(2, $lastPage) : []);
        $pages = array_slice($pages, 0, $maxPages - 1);

        $items = $first['items'];
        foreach ($pager !== null ? $pages : [] as $page) {
            $items = array_merge($items, $this->refundPage($pager->listRefundsPage($page))['items']);
        }

        $records = [];
        foreach ($items as $item) {
            if (! $this->refundBelongsTo($item, $transactionId)) {
                continue;
            }

            $reference = $this->scalarString($item['id'] ?? null);
            $amount = is_scalar($item['refundable_amount'] ?? null) && is_numeric((string) $item['refundable_amount'])
                ? Money::fromGatewayAmount((string) $item['refundable_amount'], $currency)
                : null;
            if ($reference === null || $amount === null) {
                continue;
            }

            $gatewayStatus = strtolower(trim((string) ($item['status'] ?? '')));
            $records[$reference] = new GatewayRefundRecord(
                reference: $reference,
                amount: $amount,
                status: $this->refundStatus($gatewayStatus),
                gatewayStatus: $gatewayStatus !== '' ? $gatewayStatus : null,
                createdAt: is_string($item['created_at'] ?? null) ? $this->parseDate($item['created_at']) : null,
            );
        }

        $records = array_values($records);
        usort($records, static fn (GatewayRefundRecord $a, GatewayRefundRecord $b): int => ($a->createdAt?->getTimestamp() ?? 0) <=> ($b->createdAt?->getTimestamp() ?? 0)
            ?: strcmp($a->reference, $b->reference));

        $complete = $pager !== null ? count($pages) + 1 >= $lastPage : $first['last_page'] <= 1;

        return new GatewayRefundList($records, $complete);
    }

    /**
     * @param  array<mixed>  $response
     * @return array{items: list<array>, last_page: int}
     */
    private function refundPage(array $response): array
    {
        // Accept both the bare paginator and a {status, data: paginator} wrapper.
        $paginator = is_array($response['data'] ?? null) && array_key_exists('data', $response['data'])
            ? $response['data']
            : $response;

        $items = is_array($paginator['data'] ?? null) ? array_values(array_filter($paginator['data'], 'is_array')) : [];

        return ['items' => $items, 'last_page' => (int) ($paginator['last_page'] ?? 1)];
    }

    /**
     * @param  array<mixed>  $items
     */
    private function oldestFirst(array $items): bool
    {
        $first = isset($items[0]['created_at']) && is_string($items[0]['created_at']) ? $this->parseDate($items[0]['created_at']) : null;
        $last = $items !== [] && isset($items[count($items) - 1]['created_at']) && is_string($items[count($items) - 1]['created_at'])
            ? $this->parseDate($items[count($items) - 1]['created_at'])
            : null;

        return $first !== null && $last !== null && $first < $last;
    }

    /**
     * refundable_id is a transaction id for integration refunds (refund_type 3)
     * but an invoice id for invoice refunds, and both id spaces overlap, so
     * invoice-typed items are excluded (configurable).
     *
     * @param  array<mixed>  $item
     */
    private function refundBelongsTo(array $item, string $transactionId): bool
    {
        if ($this->scalarString($item['refundable_id'] ?? null) !== $transactionId) {
            return false;
        }

        $type = strtolower((string) ($item['refundable_type'] ?? ''));
        $excluded = $this->config['refund_excluded_types'] ?? ['invoice'];
        foreach (is_array($excluded) ? $excluded : [] as $pattern) {
            if (is_string($pattern) && $pattern !== '' && str_contains($type, strtolower($pattern))) {
                return false;
            }
        }

        return true;
    }

    private function refundStatus(string $gatewayStatus): RefundStatus
    {
        $map = array_change_key_case(
            array_replace(self::REFUND_STATUS_MAP, is_array($this->config['refund_status_map'] ?? null) ? $this->config['refund_status_map'] : []),
        );

        return match ($map[$gatewayStatus] ?? 'pending') {
            'succeeded' => RefundStatus::SUCCEEDED,
            'failed' => RefundStatus::FAILED,
            default => RefundStatus::PENDING,
        };
    }

    private function scalarString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @return array<mixed>
     */
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

    /**
     * Maps the application's reason to one Fawaterk accepts. Unknown reasons are
     * sent as "Other" with the original text in the comment instead of failing.
     *
     * @return array{0: string, 1: ?string} [fawaterk reason, unmatched original text]
     */
    private function resolveRefundReason(?string $language, ?string $requested): array
    {
        $reasons = $this->refundReasons($language);
        if ($reasons === []) {
            throw new FawaterkConfigurationException('Fawaterk returned no refund reasons.');
        }

        $other = $reasons[0];
        foreach ($reasons as $reason) {
            if (strcasecmp(trim($reason), 'Other') === 0) {
                $other = $reason;
                break;
            }
        }

        $requested = $requested !== null ? trim($requested) : '';
        if ($requested === '') {
            return [$other, null];
        }

        $map = is_array($this->config['refund_reason_map'] ?? null) ? $this->config['refund_reason_map'] : [];
        $candidate = isset($map[$requested]) && is_string($map[$requested]) ? $map[$requested] : $requested;

        foreach ($reasons as $reason) {
            if (strcasecmp(trim($reason), trim($candidate)) === 0) {
                return [$reason, null];
            }
        }

        return [$other, $requested];
    }

    /** @return list<string> */
    private function refundReasons(?string $language): array
    {
        $fetch = function () use ($language): array {
            $reasons = [];
            foreach ($this->client->getRefundReasons($language) as $item) {
                $text = is_array($item)
                    ? ($item['reason'] ?? $item['name'] ?? $item['title'] ?? null)
                    : $item;
                if (is_scalar($text) && trim((string) $text) !== '') {
                    $reasons[] = (string) $text;
                }
            }

            return $reasons;
        };

        if ($this->cache === null) {
            return $fetch();
        }

        $key = 'mo7mud.payment-gateway.fawaterk.refund-reasons.'.($language ?? 'default');
        $reasons = $this->cache->get($key);
        if (is_array($reasons) && $reasons !== []) {
            return array_values(array_map('strval', $reasons));
        }

        $reasons = $fetch();
        if ($reasons !== []) {
            $this->cache->put($key, $reasons, 86400);
        }

        return $reasons;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function detectWebhookType(array $payload): string
    {
        if (isset($payload['referenceId'])) {
            return 'cancelled';
        }

        if (isset($payload['transactionHashKey'])) {
            return strtolower((string) ($payload['status'] ?? '')) === 'paid' ? 'paid' : 'pending';
        }

        if (isset($payload['transactionId'], $payload['amount'], $payload['currency']) && ! isset($payload['transaction_key'])) {
            return 'refund';
        }

        if (isset($payload['transaction_key'], $payload['transaction_id'], $payload['hashKey'])) {
            return 'failed';
        }

        throw new WebhookVerificationException('Unable to identify Fawaterk webhook type.');
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
