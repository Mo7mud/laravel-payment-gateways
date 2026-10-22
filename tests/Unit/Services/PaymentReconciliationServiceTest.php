<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Services;

use DateTimeImmutable;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentReconciliationService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PaymentReconciliationServiceTest extends TestCase
{
    public function test_failing_payments_back_off_instead_of_starving_the_batch(): void
    {
        $gateway = new class extends MockGateway {
            public function reconcilePayment(PaymentStatusRequest $request): PaymentStatusResult
            {
                if ($request->paymentReference === 'PAY-BROKEN') {
                    throw new RuntimeException('Gateway rejected the lookup.');
                }

                return parent::reconcilePayment($request);
            }
        };
        [$service, $payments, $reconciliation] = $this->build($gateway);

        $service->createPayment('mock', new CreatePaymentRequest('PAY-BROKEN', Money::fromDecimal('10.00', Currency::egp())));
        $service->createPayment('mock', new CreatePaymentRequest('PAY-OK', Money::fromDecimal('10.00', Currency::egp())));
        $gateway->setStatus('PAY-OK', PaymentStatus::SUCCEEDED);

        $first = $reconciliation->reconcileStale(new DateTimeImmutable('+1 minute'));

        self::assertSame(2, $first->examined);
        self::assertSame(1, $first->failed);
        self::assertSame(1, $first->updated);
        self::assertSame(PaymentStatus::SUCCEEDED, $payments->findByReference('PAY-OK')?->status);

        $broken = $payments->findByReference('PAY-BROKEN');
        self::assertSame(1, $broken?->reconcile_attempts);
        self::assertGreaterThan(time(), $broken?->next_reconcile_at?->getTimestamp());

        // Scheduled for later: not picked again on the next run.
        self::assertSame(0, $reconciliation->reconcileStale(new DateTimeImmutable('+1 minute'))->examined);
    }

    public function test_unpaid_payments_past_their_expiry_are_expired(): void
    {
        [$service, $payments, $reconciliation] = $this->build($gateway = new MockGateway());

        $service->createPayment('mock', new CreatePaymentRequest('PAY-OLD', Money::fromDecimal('10.00', Currency::egp())));
        $payment = $payments->findByReference('PAY-OLD');
        $payment->expires_at = new DateTimeImmutable('-2 hours');
        $payments->save($payment);

        $reconciliation->reconcileStale(new DateTimeImmutable('+1 minute'));

        self::assertSame(PaymentStatus::EXPIRED, $payments->findByReference('PAY-OLD')?->status);
    }

    /** @return array{0: PaymentService, 1: InMemoryPaymentRepository, 2: PaymentReconciliationService} */
    private function build(MockGateway $gateway): array
    {
        $payments = new InMemoryPaymentRepository();
        $refunds = new InMemoryRefundRepository();
        $service = new PaymentService(
            new GatewayManager([$gateway]),
            $payments,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
            $refunds,
        );

        return [$service, $payments, new PaymentReconciliationService($payments, $service, $refunds)];
    }
}
