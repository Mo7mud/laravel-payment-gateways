<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Mo7mud\LaravelPaymentGateway\PaymentServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;

/** Boots the package inside a Laravel application (Testbench). */
final class HttpEndpointsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('payment-gateway.kashier.payment_api_key', 'payment-api-key');
        $app['config']->set('payment-gateway.kashier.merchant_id', 'MID-TEST');
        $app['config']->set('payment-gateway.reconciliation.schedule', false);
    }

    protected function enableAdminWithoutPermission($app): void
    {
        $app['config']->set('payment-gateway.admin.enabled', true);
        $app['config']->set('payment-gateway.admin.permission', null);
    }

    public function test_the_settings_page_does_not_exist_unless_enabled(): void
    {
        $this->actingAs($this->user())->get('/payment-gateway/settings')->assertNotFound();
    }

    #[DefineEnvironment('enableAdminWithoutPermission')]
    public function test_the_settings_page_fails_closed_without_a_permission(): void
    {
        $this->actingAs($this->user())->get('/payment-gateway/settings')->assertForbidden();
        // Even with every middleware bypassed (auth, CSRF), the controller itself refuses.
        $this->withoutMiddleware()->post('/payment-gateway/settings', ['env' => ['KASHIER_SECRET_KEY' => 'x']])->assertForbidden();
    }

    public function test_an_unsigned_webhook_is_rejected(): void
    {
        $this->postJson('/payment-gateway/webhooks/kashier', [
            'event' => 'pay',
            'data' => ['merchantOrderId' => 'ORD-1', 'status' => 'SUCCESS'],
        ])->assertStatus(401);
    }

    public function test_an_unknown_gateway_is_not_found(): void
    {
        $this->postJson('/payment-gateway/webhooks/unknown', [])->assertNotFound();
    }

    private function user(): User
    {
        return (new User())->forceFill(['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test']);
    }
}
