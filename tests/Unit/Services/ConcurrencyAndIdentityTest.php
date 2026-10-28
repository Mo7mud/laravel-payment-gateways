<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Services;

use DateTimeInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentCreationResult;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundResult;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventStatus;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentNotFoundException;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Models\PaymentRefund;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\RefundRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Support\Uuid;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\Tests\Support\FakeKashierClient;
use Mo7mud\LaravelPaymentGateway\Tests\Support\KashierPayloads;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConcurrencyAndIdentityTest extends TestCase
{
    public function test_a_concurrent_request_with_the_same_idempotency_key_gets_the_first_result(): void
    {
        $gateway = new class extends MockGateway
        {
            public int $calls = 0;

            public function refund(RefundPaymentRequest $request): RefundResult
            {
                $this->calls++;

                return parent::refund($request);
            }
        };

        // Simulates the race: the lookup before the lock misses a row that a
        // concurrent request committed while this one waited for the lock.
        $refunds = new class(new InMemoryRefundRepository) implements RefundRepositoryInterface
        {
            public bool $missNextKeyLookup = false;

            public function __construct(private readonly InMemoryRefundRepository $inner) {}

            public function save(PaymentRefund $refund): PaymentRefund
            {
                return $this->inner->save($refund);
            }

            public function findByUuid(string $uuid): ?PaymentRefund
            {
                return $this->inner->findByUuid($uuid);
            }

            public function findByGatewayReference(string $gateway, string $reference): ?PaymentRefund
            {
                return $this->inner->findByGatewayReference($gateway, $reference);
            }

            public function findOldestUnmatchedPending(string $paymentReference, int $amountMinor): ?PaymentRefund
            {
                return $this->inner->findOldestUnmatchedPending($paymentReference, $amountMinor);
            }

            public function pendingFor(string $paymentReference): array
            {
                return $this->inner->pendingFor($paymentReference);
            }

            public function sumAmount(string $paymentReference, RefundStatus $status): int
            {
                return $this->inner->sumAmount($paymentReference, $status);
            }

            public function paymentsWithPendingRefunds(DateTimeInterface $before, int $limit = 100): array
            {
                return $this->inner->paymentsWithPendingRefunds($before, $limit);
            }

            public function findByIdempotencyKey(string $paymentReference, string $idempotencyKey): ?PaymentRefund
            {
                if ($this->missNextKeyLookup) {
                    $this->missNextKeyLookup = false;

                    return null;
                }

                return $this->inner->findByIdempotencyKey($paymentReference, $idempotencyKey);
            }
        };

        $service = new PaymentService(
            new GatewayManager([$gateway]),
            $payments = new InMemoryPaymentRepository,
            new PaymentStateManager,
            new InMemoryWebhookEventRepository,
            new InMemoryTransactionRunner,
            $refunds,
        );
        $service->createPayment('mock', new CreatePaymentRequest('PAY-IK', Money::fromDecimal('10.00', Currency::egp())));
        $gateway->setStatus('PAY-IK', PaymentStatus::SUCCEEDED);
        $service->getPaymentStatus(new PaymentStatusRequest('PAY-IK'));

        // The "other" request's committed row.
        $row = new PaymentRefund;
        $row->uuid = Uuid::v4();
        $row->payment_reference = 'PAY-IK';
        $row->gateway = 'mock';
        $row->status = RefundStatus::PENDING;
        $row->type = 'refund';
        $row->source = 'api';
        $row->amount_minor = 300;
        $row->currency = 'EGP';
        $row->currency_scale = 2;
        $row->idempotency_key = 'refund-key';
        $refunds->save($row);

        $refunds->missNextKeyLookup = true;
        $result = $service->refund(new RefundPaymentRequest('PAY-IK', Money::fromDecimal('3.00', Currency::egp()), idempotencyKey: 'refund-key'));

        self::assertTrue($result->metadata['idempotent_replay']);
        self::assertSame(0, $gateway->calls);
        self::assertSame(300, $refunds->sumAmount('PAY-IK', RefundStatus::PENDING));
    }

    public function test_a_new_payment_never_reuses_another_payments_merchant_order_id(): void
    {
        $gateway = new class extends MockGateway
        {
            public bool $fail = false;

            /** @var list<string> */
            public array $orders = [];

            public function createPayment(CreatePaymentRequest $request): PaymentCreationResult
            {
                $this->orders[] = $request->merchantOrderReference();
                if ($this->fail) {
                    throw new RuntimeException('Gateway timeout');
                }

                return parent::createPayment($request);
            }
        };
        $service = new PaymentService(
            new GatewayManager([$gateway]),
            $payments = new InMemoryPaymentRepository,
            new PaymentStateManager,
            new InMemoryWebhookEventRepository,
            new InMemoryTransactionRunner,
            new InMemoryRefundRepository,
        );

        $gateway->fail = true;
        try {
            $service->createPayment('mock', new CreatePaymentRequest('ORD-9', Money::fromDecimal('10.00', Currency::egp())));
        } catch (RuntimeException) {
        }
        $gateway->fail = false;
        $service->createPayment('mock', new CreatePaymentRequest('ORD-9', Money::fromDecimal('10.00', Currency::egp())));
        $retryReference = (string) $payments->findByReference('ORD-9')?->gateway_order_reference;
        self::assertMatchesRegularExpression('/^ORD-9-A2-[0-9a-f]{6}$/', $retryReference);

        // An application reference that happens to equal that merchant order id.
        $service->createPayment('mock', new CreatePaymentRequest($retryReference, Money::fromDecimal('5.00', Currency::egp())));

        $other = (string) $payments->findByReference($retryReference)?->gateway_order_reference;
        self::assertNotSame($retryReference, $other);
        self::assertStringStartsWith($retryReference.'-A1-', $other);
        // Every merchant order id ever sent to the gateway is distinct.
        self::assertSame($gateway->orders, array_values(array_unique($gateway->orders)));
    }

    public function test_a_similar_looking_merchant_order_id_does_not_resolve_to_the_payment(): void
    {
        $client = new FakeKashierClient;
        $service = new PaymentService(
            new GatewayManager([new KashierGateway($client, new KashierSignatureVerifier(KashierPayloads::API_KEY), new KashierStatusMapper, ['merchant_id' => 'MID-TEST', 'return_url' => 'https://shop.test/r'])]),
            new InMemoryPaymentRepository,
            new PaymentStateManager,
            new InMemoryWebhookEventRepository,
            new InMemoryTransactionRunner,
            new InMemoryRefundRepository,
        );
        $service->createPayment('kashier', new CreatePaymentRequest('ORD-X', Money::fromDecimal('100.00', Currency::egp())));

        $this->expectException(PaymentNotFoundException::class);
        $service->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-1', 'ORD-X-A2'), 'kashier');
    }

    public function test_a_kashier_webhook_signed_for_another_merchant_is_rejected(): void
    {
        $gateway = new KashierGateway(new FakeKashierClient, new KashierSignatureVerifier(KashierPayloads::API_KEY), new KashierStatusMapper, ['merchant_id' => 'MID-TEST']);

        $this->expectException(WebhookVerificationException::class);
        $gateway->handleWebhook(KashierPayloads::webhook('pay', 'SUCCESS', 'TX-1', 'ORD-1', extra: ['merchantId' => 'MID-OTHER']));
    }

    public function test_a_failure_never_overwrites_a_processed_event(): void
    {
        $events = new InMemoryWebhookEventRepository;
        $events->storeIfAbsent('kashier', 'evt', 'pay', 'ORD', 'hash', [], WebhookEventStatus::RECEIVED);
        $events->markStatus('kashier', 'evt', WebhookEventStatus::PROCESSED, 'applied');
        $events->markStatus('kashier', 'evt', WebhookEventStatus::FAILED, 'late failure');

        self::assertSame(WebhookEventStatus::PROCESSED, $events->find('kashier', 'evt')?->status);
    }
}
