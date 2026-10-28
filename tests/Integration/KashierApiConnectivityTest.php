<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Integration;

use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

final class KashierApiConnectivityTest extends TestCase
{
    public function test_test_environment_can_be_reached_with_a_real_secret_key(): void
    {
        $secret = getenv('KASHIER_SECRET_KEY');
        if ($secret === false || trim($secret) === '') {
            $this->markTestSkipped('KASHIER_SECRET_KEY is not configured.');
        }

        $baseUrl = rtrim(getenv('KASHIER_TEST_API_BASE_URL') ?: 'https://test-api.kashier.io', '/');
        $client = new Factory;

        // The supplied Kashier documentation explicitly documents GET /v2/account
        // with the Secret API Key in the Authorization header. This is a safer
        // authentication probe than querying a made-up payment session.
        $response = $client
            ->timeout(15)
            ->withHeaders([
                'Authorization' => $secret,
                'Accept' => 'application/json',
            ])
            ->get($baseUrl.'/v2/account');

        $this->assertSame(200, $response->status(), sprintf(
            'Kashier authentication failed with HTTP %d. Response: %s',
            $response->status(),
            $response->body(),
        ));
    }
}
