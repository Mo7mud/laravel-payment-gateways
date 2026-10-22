<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Feature;

use Mo7mud\LaravelPaymentGateway\Contracts\TransactionRunnerInterface;
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\PaymentServiceProvider;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\PaymentRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Repositories\RefundRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Repositories\WebhookEventRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Support\ReturnRedirect;
use Mo7mud\LaravelPaymentGateway\Testing\MockGateway;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use Orchestra\Testbench\TestCase;

/** R3: the return route syncs open payments and builds the redirect. */
final class ReturnEndpointTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('payment-gateway.reconciliation.schedule', false);
        $app['config']->set('payment-gateway.gateways.mock', MockGateway::class);
        $app['config']->set('payment-gateway.return.redirect_url', 'https://app.test/done/{reference}?status={status}&via={gateway}');
        $app['config']->set('payment-gateway.return.allowed_hosts', ['shop.test']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentRepositoryInterface::class, new InMemoryPaymentRepository());
        $this->app->instance(WebhookEventRepositoryInterface::class, new InMemoryWebhookEventRepository());
        $this->app->instance(RefundRepositoryInterface::class, new InMemoryRefundRepository());
        $this->app->instance(TransactionRunnerInterface::class, new InMemoryTransactionRunner());
    }

    public function test_an_open_payment_is_checked_with_the_gateway_before_the_redirect(): void
    {
        $this->create('RET-1');
        $this->mockGateway()->setStatus('RET-1', PaymentStatus::SUCCEEDED);

        $this->get('/payment-gateway/return/mock?payment_reference=RET-1&status=failed')
            ->assertRedirect('https://app.test/done/RET-1?status=succeeded&via=mock');
    }

    public function test_a_per_payment_redirect_is_used_only_while_its_host_is_allowed(): void
    {
        $this->create('RET-2', 'https://shop.test/pay/{reference}/{status}');

        $this->get('/payment-gateway/return/mock?payment_reference=RET-2')
            ->assertRedirect('https://shop.test/pay/RET-2/pending');

        config(['payment-gateway.return.allowed_hosts' => []]);

        $this->get('/payment-gateway/return/mock?payment_reference=RET-2')
            ->assertRedirect('https://app.test/done/RET-2?status=pending&via=mock');
    }

    private function create(string $reference, ?string $redirect = null): void
    {
        $this->app->make(PaymentService::class)->createPayment('mock', new CreatePaymentRequest(
            $reference,
            Money::fromMinor(10000, Currency::egp()),
            metadata: $redirect !== null ? [ReturnRedirect::METADATA_KEY => $redirect] : [],
        ));
    }

    private function mockGateway(): MockGateway
    {
        $gateway = $this->app->make(GatewayManager::class)->gateway('mock');
        self::assertInstanceOf(MockGateway::class, $gateway);

        return $gateway;
    }
}
