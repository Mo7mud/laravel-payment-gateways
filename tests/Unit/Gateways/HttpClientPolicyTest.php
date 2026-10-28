<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Sleep;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkClient;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkTokenManager;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierApiException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierClient;
use Mo7mud\LaravelPaymentGateway\Support\DecimalNumber;
use PHPUnit\Framework\TestCase;

/** What actually goes over the wire, using Laravel's HTTP fake. */
final class HttpClientPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_kashier_reads_are_retried_after_rate_limiting(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()
            ->push(['message' => 'Too many requests'], 429)
            ->push(['status' => 'SUCCESS', 'data' => []], 200)]);

        $response = $this->kashier($http)->getOrderStatus('ORD-1');

        self::assertSame([], $response['data']);
        self::assertCount(2, $http->recorded());
        $http->assertSent(static fn (Request $request): bool => str_contains($request->url(), 'search=ORD-1')
            && ! str_contains($request->url(), 'page='));
    }

    public function test_kashier_refunds_are_never_retried(): void
    {
        $http = new Factory;
        $http->fake(['*' => Factory::response(['message' => 'Upstream error'], 502)]);

        try {
            $this->kashier($http)->refund('ORDER-1', '10.00', null);
            self::fail('Expected KashierApiException.');
        } catch (KashierApiException $e) {
            self::assertTrue($e->isTransient());
        }

        self::assertCount(1, $http->recorded());
    }

    public function test_kashier_abandons_a_session_with_an_empty_json_object_once(): void
    {
        $http = new Factory;
        $http->fake(['*' => Factory::response(['message' => 'Upstream error'], 502)]);

        try {
            $this->kashier($http)->abandonPaymentSession('SESSION-1');
            self::fail('Expected KashierApiException.');
        } catch (KashierApiException) {
        }

        self::assertCount(1, $http->recorded());
        $http->assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/v3/payment/sessions/SESSION-1')
            && $request->body() === '{}');
    }

    public function test_fawaterk_amounts_are_sent_as_exact_numbers(): void
    {
        $http = new Factory;
        $http->fake(['*' => Factory::response(['status' => 'success'], 200)]);

        $tokens = new FawaterkTokenManager(
            new CacheRepository(new ArrayStore),
            'client',
            'secret',
            'test',
            'https://staging.fawaterk.test',
            static fn (string $grant, array $payload): array => ['access_token' => 'token-1', 'expires_in' => 3600],
        );
        $client = new FawaterkClient($http, $tokens, 'https://staging.fawaterk.test');

        $client->createRefund(['refund_type' => '3', 'refund_id' => 555, 'reason' => 'Other', 'refundable_amount' => new DecimalNumber('25.10')]);

        $http->assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-1')
            && str_contains($request->body(), '"refundable_amount":25.10')
            && $request->hasHeader('Content-Type', 'application/json'));
    }

    public function test_the_fawaterk_token_is_fetched_once_and_cached(): void
    {
        $calls = 0;
        $tokens = new FawaterkTokenManager(
            new CacheRepository(new ArrayStore),
            'client',
            'secret',
            'test',
            'https://staging.fawaterk.test',
            static function () use (&$calls): array {
                $calls++;

                return ['access_token' => 'token-'.$calls, 'expires_in' => 3600];
            },
        );

        self::assertSame('token-1', $tokens->accessToken());
        self::assertSame('token-1', $tokens->accessToken());
        self::assertSame(1, $calls);
    }

    private function kashier(Factory $http): KashierClient
    {
        return new KashierClient($http, 'secret', 'api-key', 'https://test-api.kashier.io', 'https://test-fep.kashier.io');
    }
}
