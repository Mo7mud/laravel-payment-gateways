<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Services;

use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundResult;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundNotification;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookResult;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventKind;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Enums\GatewayName;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    private PaymentService $service;
    private MockGateway $gateway;
    private InMemoryPaymentRepository $repository;
    private InMemoryRefundRepository $refunds;
    private InMemoryWebhookEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new MockGateway();
        $this->repository = new InMemoryPaymentRepository();
        $this->refunds = new InMemoryRefundRepository();
        $this->events = new InMemoryWebhookEventRepository();
        $this->service = $this->serviceFor($this->gateway);
    }

    /** A service sharing this test's payments, refund ledger and webhook log. */
    private function serviceFor(MockGateway $gateway): PaymentService
    {
        return new PaymentService(
            new GatewayManager([$gateway]),
            $this->repository,
            new PaymentStateManager(),
            $this->events,
            new InMemoryTransactionRunner(),
            $this->refunds,
        );
    }

    private function refundWebhookGateway(): MockGateway
    {
        return new class extends MockGateway {
            public function handleWebhook(WebhookRequest $request): WebhookResult
            {
                $amount = Money::fromDecimal((string) $request->payload['amount'], Currency::egp());

                return new WebhookResult(
                    eventId: (string) $request->payload['id'],
                    eventType: 'refund',
                    paymentReference: (string) $request->payload['reference'],
                    status: null,
                    amount: $amount,
                    gatewayStatus: 'SUCCESS',
                    rawPayload: $request->payload,
                    kind: WebhookEventKind::REFUND,
                    refund: new RefundNotification($amount, RefundStatus::SUCCEEDED, (string) $request->payload['refund_reference']),
                );
            }
        };
    }

    public function test_it_creates_a_pending_payment(): void
    {
        $result = $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-001', Money::fromDecimal('100.50', Currency::egp())),
        );

        self::assertSame('PAY-001', $result->paymentReference);
        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertNotNull($result->checkoutUrl);
    }

    public function test_status_query_updates_payment_state(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-002', Money::fromDecimal('50.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-002', PaymentStatus::SUCCEEDED);
        $result = $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-002'));

        self::assertSame(PaymentStatus::SUCCEEDED, $result->status);
    }

    public function test_webhook_updates_payment_state(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-003', Money::fromDecimal('25.00', Currency::egp())),
        );

        $result = $this->service->handleWebhook(
            new WebhookRequest([
                'event_id' => 'evt-003',
                'payment_reference' => 'PAY-003',
                'status' => PaymentStatus::SUCCEEDED->value,
            ]),
            GatewayName::MOCK,
        );

        self::assertSame(PaymentStatus::SUCCEEDED, $result->status);
    }


    public function test_partial_refund_moves_a_succeeded_payment_to_partially_refunded(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-005', Money::fromDecimal('10.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-005', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-005'));

        $result = $this->service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-005',
            amount: Money::fromDecimal('3.00', Currency::egp()),
        ));

        $payment = $this->repository->findByReference('PAY-005');

        self::assertSame(RefundStatus::SUCCEEDED, $result->status);
        self::assertSame(300, $result->amount?->minorAmount);
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $payment?->status);
    }

    public function test_failed_refund_does_not_change_payment_state(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-006', Money::fromDecimal('10.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-006', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-006'));

        $this->gateway = new class extends MockGateway {
            public function refund(RefundPaymentRequest $request): RefundResult
            {
                return new RefundResult(
                    paymentReference: $request->paymentReference,
                    status: RefundStatus::FAILED,
                    amount: $request->amount,
                    gatewayStatus: 'FAILED',
                    metadata: ['failed' => true],
                );
            }
        };
        $this->service = new PaymentService(
            new GatewayManager([$this->gateway]),
            $this->repository,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
        );

        $result = $this->service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-006',
            amount: Money::fromDecimal('3.00', Currency::egp()),
        ));

        $payment = $this->repository->findByReference('PAY-006');

        self::assertSame(RefundStatus::FAILED, $result->status);
        self::assertSame(PaymentStatus::SUCCEEDED, $payment?->status);
    }



    public function test_non_refund_webhook_still_rejects_amount_mismatch(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-011', Money::fromDecimal('10.00', Currency::egp())),
        );

        $gateway = new class extends MockGateway {
            public function handleWebhook(WebhookRequest $request): \Mo7mud\LaravelPaymentGateway\DTOs\WebhookResult
            {
                return new \Mo7mud\LaravelPaymentGateway\DTOs\WebhookResult(
                    eventId: 'pay-011',
                    eventType: 'pay',
                    paymentReference: 'PAY-011',
                    status: PaymentStatus::SUCCEEDED,
                    amount: Money::fromDecimal('7.00', Currency::egp()),
                    gatewayReference: 'order-011',
                    gatewayTransactionId: 'tx-011',
                    gatewayStatus: 'SUCCESS',
                    occurredAt: new \DateTimeImmutable(),
                    metadata: [],
                    rawPayload: ['event' => 'pay'],
                );
            }
        };

        $service = new PaymentService(
            new GatewayManager([$gateway]),
            $this->repository,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
        );

        $this->expectException(\Mo7mud\LaravelPaymentGateway\Exceptions\PaymentAmountMismatchException::class);

        $service->handleWebhook(
            new WebhookRequest(['event' => 'pay']),
            GatewayName::MOCK,
        );
    }

    public function test_refund_webhook_for_an_api_refund_is_not_counted_twice(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-010', Money::fromDecimal('10.00', Currency::egp())),
        );
        $this->gateway->setStatus('PAY-010', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-010'));

        $this->service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-010',
            amount: Money::fromDecimal('3.00', Currency::egp()),
        ));

        $service = $this->serviceFor($this->refundWebhookGateway());

        // The gateway confirms the same refund the API call already recorded.
        $service->handleWebhook(new WebhookRequest([
            'id' => 'refund-010-a', 'reference' => 'PAY-010', 'amount' => '3.00', 'refund_reference' => 'mock-refund-PAY-010',
        ]), GatewayName::MOCK);

        $payment = $this->repository->findByReference('PAY-010');
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $payment?->status);
        self::assertSame(300, $payment?->refunded_amount_minor);

        // A refund made in the gateway dashboard is still picked up.
        $result = $service->handleWebhook(new WebhookRequest([
            'id' => 'refund-010-b', 'reference' => 'PAY-010', 'amount' => '7.00', 'refund_reference' => 'DASHBOARD-1',
        ]), GatewayName::MOCK);

        $payment = $this->repository->findByReference('PAY-010');
        self::assertSame(PaymentStatus::REFUNDED, $result->status);
        self::assertSame(PaymentStatus::REFUNDED, $payment?->status);
        self::assertSame(1000, $payment?->refunded_amount_minor);
        self::assertSame(1000, $payment?->metadata['refunded_amount_minor']);
        self::assertNotNull($payment?->refunded_at);
    }

    public function test_full_refund_after_partial_refund_only_refunds_the_remaining_amount(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-007', Money::fromDecimal('10.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-007', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-007'));

        $partial = $this->service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-007',
            amount: Money::fromDecimal('3.00', Currency::egp()),
        ));

        self::assertSame(300, $partial->amount?->minorAmount);

        $full = $this->service->refund(new RefundPaymentRequest('PAY-007'));

        self::assertSame(700, $full->amount?->minorAmount);

        $payment = $this->repository->findByReference('PAY-007');
        self::assertSame(PaymentStatus::REFUNDED, $payment?->status);
        self::assertSame(1000, $payment?->metadata['refunded_amount_minor']);
        self::assertNotNull($payment?->refunded_at);
    }

    public function test_refund_rejects_reserved_gateway_metadata_override(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-008', Money::fromDecimal('10.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-008', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-008'));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-008',
            amount: Money::fromDecimal('1.00', Currency::egp()),
            metadata: ['gateway_reference' => 'OTHER-ORDER'],
        ));
    }


    public function test_refund_rejects_amount_above_remaining_before_calling_gateway(): void
    {
        $gateway = new class extends MockGateway {
            public int $refundCalls = 0;

            public function refund(RefundPaymentRequest $request): RefundResult
            {
                $this->refundCalls++;

                return parent::refund($request);
            }
        };

        $service = new PaymentService(
            new GatewayManager([$gateway]),
            $repository = new InMemoryPaymentRepository(),
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
        );

        $service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-OVER-1', Money::fromDecimal('10.00', Currency::egp())),
        );
        $gateway->setStatus('PAY-OVER-1', PaymentStatus::SUCCEEDED);
        $service->getPaymentStatus(new PaymentStatusRequest('PAY-OVER-1'));
        $service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-OVER-1',
            amount: Money::fromDecimal('8.00', Currency::egp()),
        ));

        try {
            $service->refund(new RefundPaymentRequest(
                paymentReference: 'PAY-OVER-1',
                amount: Money::fromDecimal('3.00', Currency::egp()),
            ));
            self::fail('Expected an InvalidArgumentException for a refund above the remaining amount.');
        } catch (\InvalidArgumentException) {
            // Expected.
        }

        self::assertSame(1, $gateway->refundCalls);
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $repository->findByReference('PAY-OVER-1')?->status);
    }

    public function test_refund_rejects_amount_above_remaining_refundable_amount(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-011', Money::fromDecimal('10.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-011', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-011'));

        $this->service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-011',
            amount: Money::fromDecimal('7.00', Currency::egp()),
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-011',
            amount: Money::fromDecimal('4.00', Currency::egp()),
        ));
    }

    public function test_gateway_refund_overrun_is_rejected_without_persisting_the_refund(): void
    {
        $gateway = new class extends MockGateway {
            public function refund(RefundPaymentRequest $request): RefundResult
            {
                return new RefundResult(
                    paymentReference: $request->paymentReference,
                    status: RefundStatus::SUCCEEDED,
                    amount: Money::fromDecimal('4.00', Currency::egp()),
                    refundReference: 'OVERRUN-1',
                    gatewayStatus: 'SUCCESS',
                );
            }
        };

        $repository = new InMemoryPaymentRepository();
        $service = new PaymentService(
            new GatewayManager([$gateway]),
            $repository,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
        );

        $service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-OVER-2', Money::fromDecimal('10.00', Currency::egp())),
        );
        $gateway->setStatus('PAY-OVER-2', PaymentStatus::SUCCEEDED);
        $service->getPaymentStatus(new PaymentStatusRequest('PAY-OVER-2'));

        try {
            $service->refund(new RefundPaymentRequest(
                paymentReference: 'PAY-OVER-2',
                amount: Money::fromDecimal('3.00', Currency::egp()),
            ));
            self::fail('Expected an InvalidArgumentException when the Gateway reports a larger refund than requested.');
        } catch (\InvalidArgumentException) {
            // Expected.
        }

        $payment = $repository->findByReference('PAY-OVER-2');
        self::assertSame(PaymentStatus::SUCCEEDED, $payment?->status);
        self::assertSame(0, $payment?->metadata['refunded_amount_minor'] ?? 0);
        self::assertNull($payment?->refunded_at);
    }

    public function test_partial_refund_does_not_set_refunded_at_until_fully_refunded(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-009', Money::fromDecimal('10.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-009', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-009'));

        $this->service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-009',
            amount: Money::fromDecimal('3.00', Currency::egp()),
        ));

        $payment = $this->repository->findByReference('PAY-009');
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $payment?->status);
        self::assertNull($payment?->refunded_at);
    }

    public function test_refund_reconciliation_pending_does_not_downgrade_succeeded_payment(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-012', Money::fromDecimal('10.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-012', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-012'));

        $pendingGateway = new class extends MockGateway {
            public function getPaymentStatus(PaymentStatusRequest $request): PaymentStatusResult
            {
                return new PaymentStatusResult(
                    paymentReference: $request->paymentReference,
                    status: PaymentStatus::PENDING,
                    amount: Money::fromDecimal('10.00', Currency::egp()),
                    gatewayStatus: 'PENDING',
                );
            }
        };

        $service = new PaymentService(
            new GatewayManager([$pendingGateway]),
            $this->repository,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
        );

        $result = $service->refund(new RefundPaymentRequest(
            paymentReference: 'PAY-012',
            amount: Money::fromDecimal('1.00', Currency::egp()),
        ));

        $payment = $this->repository->findByReference('PAY-012');

        self::assertSame(RefundStatus::SUCCEEDED, $result->status);
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $payment?->status);
    }

    public function test_duplicate_final_refund_webhook_does_not_repeat_side_effects(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-DUP-REFUND', Money::fromDecimal('10.00', Currency::egp())),
        );
        $this->gateway->setStatus('PAY-DUP-REFUND', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-DUP-REFUND'));

        $service = $this->serviceFor($this->refundWebhookGateway());
        $payload = ['id' => 'refund-final-1', 'reference' => 'PAY-DUP-REFUND', 'amount' => '10.00', 'refund_reference' => 'TX-R-1'];

        $first = $service->handleWebhook(new WebhookRequest($payload), GatewayName::MOCK);
        $afterFirst = $this->repository->findByReference('PAY-DUP-REFUND');

        $second = $service->handleWebhook(new WebhookRequest($payload), GatewayName::MOCK);
        $afterSecond = $this->repository->findByReference('PAY-DUP-REFUND');

        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertSame(PaymentStatus::REFUNDED, $afterFirst?->status);
        self::assertNotNull($afterFirst?->refunded_at);
        self::assertSame(1000, $afterSecond?->refunded_amount_minor);
        self::assertSame($afterFirst?->metadata, $afterSecond?->metadata);
        self::assertEquals($afterFirst?->refunded_at, $afterSecond?->refunded_at);
    }

    public function test_full_refund_moves_a_succeeded_payment_to_refunded(): void
    {
        $this->service->createPayment(
            GatewayName::MOCK,
            new CreatePaymentRequest('PAY-004', Money::fromDecimal('25.00', Currency::egp())),
        );

        $this->gateway->setStatus('PAY-004', PaymentStatus::SUCCEEDED);
        $this->service->getPaymentStatus(new PaymentStatusRequest('PAY-004'));

        $result = $this->service->refund(new RefundPaymentRequest('PAY-004'));
        $payment = $this->repository->findByReference('PAY-004');

        self::assertSame('succeeded', $result->status->value);
        self::assertNotNull($result->amount);
        self::assertSame(2500, $result->amount->minorAmount);
        self::assertSame(PaymentStatus::REFUNDED, $payment?->status);
    }
}
