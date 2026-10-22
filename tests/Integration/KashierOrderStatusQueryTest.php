<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Integration;

use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class KashierOrderStatusQueryTest extends TestCase
{
    private function client(): Factory
    {
        $secret = getenv('KASHIER_SECRET_KEY') ?: '';
        if ($secret === '') {
            $this->markTestSkipped('KASHIER_SECRET_KEY is not configured.');
        }

        return (new Factory())
            ->timeout(20)
            ->withHeaders(['Authorization' => $secret, 'Accept' => 'application/json']);
    }

    public function test_real_session_status_endpoint_can_retrieve_a_known_paid_session(): void
    {
        $sessionId = getenv('KASHIER_TEST_SESSION_ID') ?: '';
        if ($sessionId === '') {
            $this->markTestSkipped('KASHIER_TEST_SESSION_ID is required.');
        }

        $baseUrl = rtrim(getenv('KASHIER_TEST_API_BASE_URL') ?: 'https://test-api.kashier.io', '/');
        $response = $this->client()->get($baseUrl . '/v3/payment/sessions/' . rawurlencode($sessionId) . '/payment');

        $this->assertSame(200, $response->status(), $response->body());
        $json = $response->json();
        $this->assertSame('success', $json['message'] ?? null, $response->body());
        $this->assertSame($sessionId, $json['data']['sessionId'] ?? null, $response->body());
        $this->assertSame('PAID', $json['data']['status'] ?? null, $response->body());
    }

    public function test_real_order_reconciliation_endpoint_can_retrieve_a_known_merchant_order(): void
    {
        $merchantOrderId = getenv('KASHIER_TEST_MERCHANT_ORDER_ID') ?: '';
        if ($merchantOrderId === '') {
            $this->markTestSkipped('KASHIER_TEST_MERCHANT_ORDER_ID is required.');
        }

        $baseUrl = rtrim(getenv('KASHIER_TEST_API_BASE_URL') ?: 'https://test-api.kashier.io', '/');
        $response = $this->client()->get($baseUrl . '/v3/payment/orders', [
            'search' => $merchantOrderId,
            'startDate' => getenv('KASHIER_TEST_START_DATE') ?: date('Y-m-d'),
            'endDate' => getenv('KASHIER_TEST_END_DATE') ?: date('Y-m-d', strtotime('+1 day')),
        ]);

        $this->assertSame(200, $response->status(), $response->body());
        $json = $response->json();
        $this->assertSame('SUCCESS', $json['status'] ?? null, $response->body());
        $this->assertNotEmpty($json['data'] ?? [], $response->body());
        $this->assertSame($merchantOrderId, $json['data'][0]['merchantOrderId'] ?? null, $response->body());
    }
}
