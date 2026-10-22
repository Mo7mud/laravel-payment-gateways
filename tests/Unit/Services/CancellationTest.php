<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Services;

use Illuminate\Events\Dispatcher;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Events\LatePaymentReceived;
use Mo7mud\LaravelPaymentGateway\Events\PaymentClosed;
use Mo7mud\LaravelPaymentGateway\Events\PaymentSucceeded;
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

/** R16: cancelling an open payment. */
final class CancellationTest extends TestCase
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
        $events->setTransactionManagerResolver(static fn () => null);
        $events->listen('*', function (string $name, array $payload): void {
            $this->dispatched[] = $payload[0];
        });

        $this->service = new PaymentService(
            new GatewayManager([
                new KashierGateway(
                    $this->client,
                    new KashierSignatureVerifier(KashierPayloads::API_KEY),
                    new KashierStatusMapper(),
                    ['merchant_id' => 'MID-TEST', 'return_url' => 'https://shop.test/return'],
                ),
                new MockGateway(),
            ]),
            $this->payments,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
            new InMemoryRefundRepository(),
            $events,
        );
    }

    public function test_kashier_abandons_the_session_and_a_later_capture_is_a_late_payment(): void
    {
        $this->create('kashier', 'ORD-C1');

        self::assertSame(PaymentStatus::CANCELLED, $this->service->cancelPayment('ORD-C1'));
        self::assertSame(['SESSION-1'], $this->client->abandoned);
        self::assertSame('ABANDONED', $this->payments->findByReference('ORD-C1')?->gateway_status);
        self::assertTrue($this->wasDispatched(PaymentClosed::class));

        // Already closed: no second gateway call.
        self::assertSame(PaymentStatus::CANCELLED, $this->service->cancelPayment('ORD-C1'));
        self::assertCount(1, $this->client->abandoned);

        $this->client->sessionStatus = 'PAID';
        $this->service->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-OK', 'ORD-C1'), 'kashier');

        self::assertTrue((bool) $this->payments->findByReference('ORD-C1')?->late_payment);
        self::assertTrue($this->wasDispatched(LatePaymentReceived::class));
    }

    public function test_a_payment_captured_before_the_cancel_stays_paid(): void
    {
        $this->create('kashier', 'ORD-C2');
        $this->client->sessionStatus = 'PAID';

        self::assertSame(PaymentStatus::SUCCEEDED, $this->service->cancelPayment('ORD-C2'));
        self::assertFalse((bool) $this->payments->findByReference('ORD-C2')?->late_payment);
        self::assertTrue($this->wasDispatched(PaymentSucceeded::class));
        self::assertFalse($this->wasDispatched(PaymentClosed::class));
    }

    public function test_without_a_cancellation_api_the_payment_is_cancelled_locally_and_paid_ones_are_untouched(): void
    {
        $this->create('mock', 'PAY-C3');
        self::assertSame(PaymentStatus::CANCELLED, $this->service->cancelPayment('PAY-C3'));

        $this->create('mock', 'PAY-C4');
        $this->service->handleWebhook(new WebhookRequest([
            'event_id' => 'evt-c4',
            'payment_reference' => 'PAY-C4',
            'status' => 'succeeded',
        ]), 'mock');

        self::assertSame(PaymentStatus::SUCCEEDED, $this->service->cancelPayment('PAY-C4'));
        self::assertSame(PaymentStatus::SUCCEEDED, $this->payments->findByReference('PAY-C4')?->status);
    }

    private function create(string $gateway, string $reference): void
    {
        $this->service->createPayment($gateway, new CreatePaymentRequest($reference, Money::fromDecimal('100.00', Currency::egp())));
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
