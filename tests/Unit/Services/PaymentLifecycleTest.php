<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Services;

use DateTimeImmutable;
use Illuminate\Events\Dispatcher;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundResult;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Events\LatePaymentReceived;
use Mo7mud\LaravelPaymentGateway\Events\PaymentAttemptFailed;
use Mo7mud\LaravelPaymentGateway\Events\PaymentSucceeded;
use Mo7mud\LaravelPaymentGateway\Exceptions\InvalidRefundException;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentReferenceConflictException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierApiException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\Tests\Support\FakeKashierClient;
use Mo7mud\LaravelPaymentGateway\Tests\Support\KashierPayloads;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

/** End-to-end flows through PaymentService with a scripted Kashier API. */
final class PaymentLifecycleTest extends TestCase
{
    private FakeKashierClient $client;
    private InMemoryPaymentRepository $payments;
    private PaymentService $service;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeKashierClient();
        $this->payments = new InMemoryPaymentRepository();

        $events = new Dispatcher();
        // A Laravel application sets this resolver (DB transaction manager);
        // a bare Dispatcher has none and fails on ShouldDispatchAfterCommit events.
        $events->setTransactionManagerResolver(static fn () => null);
        $events->listen('*', function (string $name, array $payload): void {
            $this->dispatched[] = $payload[0];
        });

        $this->service = new PaymentService(
            new GatewayManager([new KashierGateway(
                $this->client,
                new KashierSignatureVerifier(KashierPayloads::API_KEY),
                new KashierStatusMapper(),
                ['merchant_id' => 'MID-TEST', 'return_url' => 'https://shop.test/return'],
            )]),
            $this->payments,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
            new InMemoryRefundRepository(),
            $events,
        );
    }

    public function test_a_failed_attempt_does_not_close_the_payment_and_a_retry_can_succeed(): void
    {
        $this->create('ORD-1');

        $failed = $this->service->handleWebhook(KashierPayloads::webhook('pay', 'FAILURE', 'TX-F1', 'ORD-1'), 'kashier');
        self::assertSame(PaymentStatus::PENDING, $failed->status);
        self::assertSame(PaymentStatus::PENDING, $this->payments->findByReference('ORD-1')?->status);
        self::assertTrue($this->wasDispatched(PaymentAttemptFailed::class));

        $this->client->sessionStatus = 'PAID';
        $this->service->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-OK', 'ORD-1'), 'kashier');

        $payment = $this->payments->findByReference('ORD-1');
        self::assertSame(PaymentStatus::SUCCEEDED, $payment?->status);
        self::assertFalse((bool) $payment?->late_payment);
        self::assertSame('TX-OK', $payment?->gateway_transaction_id);
        self::assertTrue($this->wasDispatched(PaymentSucceeded::class));
    }

    public function test_a_success_claim_the_gateway_does_not_confirm_changes_nothing(): void
    {
        $this->create('ORD-2');
        $this->client->sessionStatus = 'OPENED';

        $result = $this->service->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-X', 'ORD-2'), 'kashier');

        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertSame(PaymentStatus::PENDING, $this->payments->findByReference('ORD-2')?->status);
        self::assertFalse($this->wasDispatched(PaymentSucceeded::class));
    }

    public function test_out_of_order_notifications_are_ignored_without_errors(): void
    {
        $this->create('ORD-3');
        $this->client->sessionStatus = 'PAID';
        $this->service->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-OK', 'ORD-3'), 'kashier');

        // A delayed failure of an earlier attempt arrives after the success.
        $late = $this->service->handleWebhook(KashierPayloads::webhook('pay', 'FAILURE', 'TX-OLD', 'ORD-3'), 'kashier');

        self::assertSame(PaymentStatus::SUCCEEDED, $late->status);
        self::assertSame(PaymentStatus::SUCCEEDED, $this->payments->findByReference('ORD-3')?->status);
    }

    public function test_a_capture_after_expiry_is_recorded_as_a_late_payment(): void
    {
        $this->create('ORD-4');
        self::assertTrue($this->service->expireIfDue('ORD-4', new DateTimeImmutable('+3 hours')));
        self::assertSame(PaymentStatus::EXPIRED, $this->payments->findByReference('ORD-4')?->status);

        $this->client->sessionStatus = 'PAID';
        $this->service->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-OK', 'ORD-4'), 'kashier');

        $payment = $this->payments->findByReference('ORD-4');
        self::assertSame(PaymentStatus::SUCCEEDED, $payment?->status);
        self::assertTrue((bool) $payment?->late_payment);
        self::assertTrue($this->wasDispatched(LatePaymentReceived::class));
    }

    public function test_creating_the_same_payment_twice_returns_the_original_checkout(): void
    {
        $first = $this->create('ORD-5');
        $second = $this->create('ORD-5');

        self::assertSame(1, $this->client->sessions);
        self::assertSame($first->checkoutUrl, $second->checkoutUrl);
        self::assertNotNull($second->checkoutUrl);
    }

    public function test_reusing_a_reference_for_another_amount_is_rejected(): void
    {
        $this->create('ORD-6');

        $this->expectException(PaymentReferenceConflictException::class);
        $this->service->createPayment('kashier', new CreatePaymentRequest('ORD-6', Money::fromDecimal('99.00', Currency::egp())));
    }

    public function test_a_failed_creation_is_retried_with_a_new_merchant_order_id(): void
    {
        $this->client->failCreate = true;

        try {
            $this->create('ORD-7');
            self::fail('Expected the gateway error to propagate.');
        } catch (KashierApiException) {
            // Expected.
        }

        self::assertSame(PaymentStatus::CREATION_FAILED, $this->payments->findByReference('ORD-7')?->status);

        $this->client->failCreate = false;
        $result = $this->create('ORD-7');

        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertCount(2, $this->client->createdOrders);
        self::assertSame('ORD-7', $this->client->createdOrders[0]);
        self::assertMatchesRegularExpression('/^ORD-7-A2-[0-9a-f]{6}$/', $this->client->createdOrders[1]);

        // Webhooks for the retried attempt still find the payment.
        $this->client->sessionStatus = 'PAID';
        $this->service->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-OK', $this->client->createdOrders[1]), 'kashier');
        self::assertSame(PaymentStatus::SUCCEEDED, $this->payments->findByReference('ORD-7')?->status);
    }

    public function test_status_queries_ignore_identifiers_supplied_by_the_caller(): void
    {
        $this->create('ORD-8');
        $this->client->sessionStatus = 'PAID';

        // The stored session is used, not the one in the request.
        $result = $this->service->getPaymentStatus(new PaymentStatusRequest('ORD-8', 'SOMEONE-ELSES-SESSION', 'TX-OTHER'));

        self::assertSame('SESSION-1', $result->gatewayReference);
    }

    public function test_pending_refunds_reserve_their_amount_and_idempotency_keys_replay(): void
    {
        $gateway = new class extends MockGateway {
            public int $calls = 0;

            public function refund(RefundPaymentRequest $request): RefundResult
            {
                $this->calls++;

                return new RefundResult($request->paymentReference, RefundStatus::PENDING, $request->amount);
            }
        };

        $service = new PaymentService(
            new GatewayManager([$gateway]),
            $payments = new InMemoryPaymentRepository(),
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
            new InMemoryRefundRepository(),
        );

        $service->createPayment('mock', new CreatePaymentRequest('PAY-R', Money::fromDecimal('10.00', Currency::egp())));
        $gateway->setStatus('PAY-R', PaymentStatus::SUCCEEDED);
        $service->getPaymentStatus(new PaymentStatusRequest('PAY-R'));

        $first = $service->refund(new RefundPaymentRequest('PAY-R', Money::fromDecimal('6.00', Currency::egp()), idempotencyKey: 'refund-1'));
        $replay = $service->refund(new RefundPaymentRequest('PAY-R', Money::fromDecimal('6.00', Currency::egp()), idempotencyKey: 'refund-1'));

        self::assertSame(RefundStatus::PENDING, $first->status);
        self::assertTrue($replay->metadata['idempotent_replay']);
        self::assertSame(1, $gateway->calls);
        // Pending refunds are not refunded money yet.
        self::assertSame(PaymentStatus::SUCCEEDED, $payments->findByReference('PAY-R')?->status);

        $this->expectException(InvalidRefundException::class);
        $service->refund(new RefundPaymentRequest('PAY-R', Money::fromDecimal('5.00', Currency::egp())));
    }

    public function test_a_signed_payment_notification_relabelled_as_a_refund_books_nothing(): void
    {
        $this->create('ORD-RELABEL');
        $this->client->sessionStatus = 'PAID';
        $this->service->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-OK', 'ORD-RELABEL'), 'kashier');
        $this->dispatched = [];

        // Same signed data, unsigned top-level event changed to "refund".
        $result = $this->service->handleWebhook(KashierPayloads::webhook('refund', 'SUCCESS', 'TX-OK', 'ORD-RELABEL'), 'kashier');

        $payment = $this->payments->findByReference('ORD-RELABEL');
        self::assertSame(PaymentStatus::SUCCEEDED, $payment?->status);
        self::assertSame(0, (int) $payment?->refunded_amount_minor);
        self::assertStringStartsWith('unconfirmed', (string) $result->processingNote);
        self::assertFalse($this->wasDispatched(\Mo7mud\LaravelPaymentGateway\Events\PaymentRefunded::class));
    }

    public function test_a_signed_payment_notification_relabelled_as_a_void_does_not_cancel_an_open_payment(): void
    {
        $this->create('ORD-VOID');
        $this->client->sessionStatus = 'OPENED';

        $result = $this->service->handleWebhook(KashierPayloads::webhook('void', 'SUCCESS', 'TX-V', 'ORD-VOID'), 'kashier');

        self::assertSame(PaymentStatus::PENDING, $this->payments->findByReference('ORD-VOID')?->status);
        self::assertStringStartsWith('unconfirmed', (string) $result->processingNote);
    }

    public function test_reconciliation_keeps_the_payment_open_after_a_failed_attempt_while_the_session_is_open(): void
    {
        $this->create('ORD-RETRY');
        $this->client->sessionStatus = 'OPENED';
        $this->client->orders = [[
            'merchantOrderId' => 'ORD-RETRY',
            'sessionId' => 'SESSION-1',
            'orderId' => 'K-ORDER-RETRY',
            'status' => 'FAILURE',
            'order' => ['amount' => 100, 'currency' => 'EGP'],
            'transactions' => [['transactionId' => 'TX-DECLINED', 'operation' => 'pay', 'status' => 'FAILURE']],
        ]];

        $this->service->reconcilePayment(new PaymentStatusRequest('ORD-RETRY'));
        self::assertSame(PaymentStatus::PENDING, $this->payments->findByReference('ORD-RETRY')?->status);

        $this->client->sessionStatus = 'EXPIRED';
        $this->service->reconcilePayment(new PaymentStatusRequest('ORD-RETRY'));
        self::assertSame(PaymentStatus::EXPIRED, $this->payments->findByReference('ORD-RETRY')?->status);
    }

    private function create(string $reference): \Mo7mud\LaravelPaymentGateway\DTOs\PaymentCreationResult
    {
        return $this->service->createPayment('kashier', new CreatePaymentRequest($reference, Money::fromDecimal('100.00', Currency::egp())));
    }

    private function wasDispatched(string $class): bool
    {
        foreach ($this->dispatched as $event) {
            if ($event instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
