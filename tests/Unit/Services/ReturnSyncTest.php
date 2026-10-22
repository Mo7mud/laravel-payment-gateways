<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Services;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use InvalidArgumentException;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusResult;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Support\ReturnRedirect;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

/** R3: the return page asks the gateway API, never the query string. */
final class ReturnSyncTest extends TestCase
{
    public function test_an_open_payment_is_checked_with_the_gateway_at_most_once_per_window(): void
    {
        $gateway = new class () extends MockGateway {
            public int $statusQueries = 0;

            public function getPaymentStatus(PaymentStatusRequest $request): PaymentStatusResult
            {
                $this->statusQueries++;

                return parent::getPaymentStatus($request);
            }
        };
        $service = $this->service($gateway, ['return_sync_status' => true]);

        $this->create($service, 'RET-1');
        $gateway->setStatus('RET-1', PaymentStatus::SUCCEEDED);
        // The query claims a failure; only the API answer counts.
        $paid = $service->handleReturn(new PaymentReturnRequest(['payment_reference' => 'RET-1', 'status' => 'failed']), 'mock');
        self::assertSame(PaymentStatus::SUCCEEDED, $paid->localStatus);
        self::assertSame(1, $gateway->statusQueries);

        $this->create($service, 'RET-2');
        $service->handleReturn(new PaymentReturnRequest(['payment_reference' => 'RET-2']), 'mock');
        $reloaded = $service->handleReturn(new PaymentReturnRequest(['payment_reference' => 'RET-2']), 'mock');
        self::assertSame(PaymentStatus::PENDING, $reloaded->localStatus);
        self::assertSame(2, $gateway->statusQueries, 'A reloaded return page does not query the gateway again.');

        // A paid payment is never queried.
        $service->handleReturn(new PaymentReturnRequest(['payment_reference' => 'RET-1']), 'mock');
        self::assertSame(2, $gateway->statusQueries);
    }

    public function test_a_per_payment_redirect_must_use_an_allowed_host(): void
    {
        $service = $this->service(new MockGateway(), ['return_allowed_hosts' => ['shop.test']]);

        $this->create($service, 'RET-3', 'https://SHOP.test/paid/{reference}?s={status}');
        self::assertSame(['shop.test'], ReturnRedirect::hostsFromString(' shop.test, ,'));

        foreach ([
            'https://evil.test/x',
            'javascript://shop.test/%0Aalert(1)',
            'https://user@shop.test/x',
            'https://evil.test\\@shop.test/x',
            '//shop.test/x',
        ] as $url) {
            self::assertFalse(ReturnRedirect::isAllowed($url, ['shop.test']), $url);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->create($service, 'RET-4', 'https://evil.test/paid');
    }

    private function service(MockGateway $gateway, array $options): PaymentService
    {
        return new PaymentService(
            new GatewayManager([$gateway]),
            new InMemoryPaymentRepository(),
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
            options: $options,
            cache: new CacheRepository(new ArrayStore()),
        );
    }

    private function create(PaymentService $service, string $reference, ?string $redirect = null): void
    {
        $service->createPayment('mock', new CreatePaymentRequest(
            $reference,
            Money::fromMinor(10000, Currency::egp()),
            metadata: $redirect !== null ? [ReturnRedirect::METADATA_KEY => $redirect] : [],
        ));
    }
}
