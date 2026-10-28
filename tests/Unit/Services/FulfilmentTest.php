<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Services;

use DateTimeImmutable;
use Illuminate\Events\Dispatcher;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\Events\PaymentStatusChanged;
use Mo7mud\LaravelPaymentGateway\Events\PaymentSucceeded;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentNotPaidException;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/** R1: a failed PaymentSucceeded listener must not lose the fulfilment. */
final class FulfilmentTest extends TestCase
{
    private InMemoryPaymentRepository $payments;

    private Dispatcher $events;

    private AbstractLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = new InMemoryPaymentRepository;
        $this->events = new Dispatcher;
        $this->events->setTransactionManagerResolver(static fn () => null);
        $this->logger = new class extends AbstractLogger
        {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }

    public function test_a_failing_listener_does_not_fail_the_webhook_and_the_event_is_raised_again_until_fulfilled(): void
    {
        $service = $this->service();
        $calls = 0;
        $this->events->listen(PaymentStatusChanged::class, static function (): void {
            throw new RuntimeException('audit log is down');
        });
        $this->events->listen(PaymentSucceeded::class, static function () use (&$calls): void {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('database went away');
            }
        });

        $this->create($service, 'PAY-1');
        $first = $service->handleWebhook($this->paidWebhook('PAY-1'), 'mock');
        $retry = $service->handleWebhook($this->paidWebhook('PAY-1'), 'mock');

        self::assertFalse($first->duplicate);
        self::assertTrue($retry->duplicate);
        self::assertSame(1, $calls, 'Other events still reach their listeners after one listener fails.');
        self::assertContains('payment.listener_failed', $this->logger->messages);

        $tooSoon = new DateTimeImmutable('+1 minute');
        self::assertSame(0, $service->redispatchUnfulfilled($tooSoon, 100, $tooSoon), 'The first dispatch gets time to finish.');

        $soon = new DateTimeImmutable('+10 minutes');
        self::assertSame(1, $service->redispatchUnfulfilled($soon, 100, $soon));
        self::assertSame(2, $calls);

        self::assertTrue($service->markFulfilled('PAY-1'));
        self::assertFalse($service->markFulfilled('PAY-1'));
        self::assertNotNull($this->payments->findByReference('PAY-1')?->fulfilled_at);

        $later = new DateTimeImmutable('+1 day');
        self::assertSame(0, $service->redispatchUnfulfilled($later, 100, $later));
        self::assertSame(2, $calls);
    }

    public function test_redispatch_backs_off_and_stops_after_the_maximum(): void
    {
        $service = $this->service(['fulfilment_max_dispatches' => 2, 'fulfilment_base_delay_seconds' => 300]);
        $calls = 0;
        $this->events->listen(PaymentSucceeded::class, static function () use (&$calls): void {
            $calls++;
        });

        $this->create($service, 'PAY-2');
        $service->handleWebhook($this->paidWebhook('PAY-2'), 'mock');
        self::assertSame(1, $calls);

        $now = new DateTimeImmutable('+10 minutes');
        self::assertSame(1, $service->redispatchUnfulfilled($now, 100, $now));
        self::assertSame(0, $service->redispatchUnfulfilled($now, 100, $now), 'Not due before the back-off delay.');
        self::assertSame(1, $service->redispatchUnfulfilled($now, 100, $now->modify('+301 seconds')));
        self::assertSame(0, $service->redispatchUnfulfilled($now, 100, $now->modify('+1 day')), 'Stops after the maximum.');
        self::assertSame(3, $calls);
        self::assertContains('payment.fulfilment_redispatch_exhausted', $this->logger->messages);
        self::assertSame(0, $service->redispatchUnfulfilled(new DateTimeImmutable('-1 hour')), 'Recent payments wait for older_than.');
    }

    public function test_an_unpaid_payment_cannot_be_marked_fulfilled(): void
    {
        $service = $this->service();
        $this->create($service, 'PAY-3');

        $this->expectException(PaymentNotPaidException::class);
        $service->markFulfilled('PAY-3');
    }

    private function service(array $options = []): PaymentService
    {
        return new PaymentService(
            new GatewayManager([new MockGateway]),
            $this->payments,
            new PaymentStateManager,
            new InMemoryWebhookEventRepository,
            new InMemoryTransactionRunner,
            events: $this->events,
            logger: $this->logger,
            options: $options,
        );
    }

    private function create(PaymentService $service, string $reference): void
    {
        $service->createPayment('mock', new CreatePaymentRequest($reference, Money::fromMinor(10000, Currency::egp())));
    }

    private function paidWebhook(string $reference): WebhookRequest
    {
        return new WebhookRequest([
            'event_id' => 'evt-'.$reference,
            'payment_reference' => $reference,
            'status' => 'succeeded',
            'gateway_status' => 'SUCCESS',
            'gateway_reference' => 'mock-'.$reference,
        ]);
    }
}
