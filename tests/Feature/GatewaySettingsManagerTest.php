<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Mo7mud\LaravelPaymentGateway\Admin\EnvironmentManager;
use Mo7mud\LaravelPaymentGateway\Admin\GatewaySettingsManager;
use Mo7mud\LaravelPaymentGateway\Admin\PaymentIntegrationService;
use Mo7mud\LaravelPaymentGateway\PaymentServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GatewaySettingsManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $path;

    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('payment-gateway.reconciliation.schedule', false);
        $app['config']->set('payment-gateway.admin.enabled', true);
        $app['config']->set('payment-gateway.admin.permission', 'manage-payment-gateways');
        $app['config']->set('payment-gateway.admin.layout', 'pg-test::layout');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/pg-settings-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->path = $this->directory.'/.env';
        // A pre-upgrade .env: legacy unprefixed keys plus one live value.
        file_put_contents($this->path, "KASHIER_SECRET_KEY=stored-secret\nKASHIER_MERCHANT_ID=MID-1\nKASHIER_LIVE_MERCHANT_ID=MID-LIVE\n");

        $this->app->instance(EnvironmentManager::class, new EnvironmentManager($this->path));
        $this->app->forgetInstance(GatewaySettingsManager::class);
        $this->app['view']->addNamespace('pg-test', dirname(__DIR__).'/Fixtures/views');
        Gate::define('manage-payment-gateways', static fn ($user): bool => (int) $user->getAuthIdentifier() === 1);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $name) {
            $file = $this->directory.'/'.$name;
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_access_fails_closed_and_follows_the_gate(): void
    {
        $manager = $this->manager();

        $this->assertTrue($manager->canAccess($this->user(1)));
        $this->assertFalse($manager->canAccess($this->user(2)));
        $manager->authorize($this->user(1));

        config()->set('payment-gateway.admin.permission', null);
        $this->assertFalse($manager->canAccess($this->user(1)));

        try {
            $manager->authorize($this->user(1));
            $this->fail('A missing permission must lock the settings.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_gateways_are_grouped_by_mode_and_secrets_stay_masked(): void
    {
        $manager = $this->manager();
        $kashier = $manager->gateways()['kashier'];

        $this->assertSame('test', $kashier['mode']['current']);
        $this->assertFalse($kashier['enabled']);
        $this->assertIsInt($kashier['id']);
        // Until the first save, the active mode shows the legacy keys.
        $this->assertSame('MID-1', $kashier['credentials']['test']['KASHIER_TEST_MERCHANT_ID']['value']);
        $this->assertSame('MID-LIVE', $kashier['credentials']['live']['KASHIER_LIVE_MERCHANT_ID']['value']);
        $this->assertSame(GatewaySettingsManager::SECRET_PLACEHOLDER, $kashier['credentials']['test']['KASHIER_TEST_SECRET_KEY']['value']);
        $this->assertNull($kashier['credentials']['live']['KASHIER_LIVE_SECRET_KEY']['value']);
        $this->assertSame('secret_key', $kashier['credentials']['live']['KASHIER_LIVE_SECRET_KEY']['name']);
        $this->assertArrayHasKey('KASHIER_ALLOWED_METHODS', $kashier['settings']);
        $this->assertArrayNotHasKey('KASHIER_MERCHANT_ID', $manager->fields());
        $this->assertContains('max:10', $kashier['settings']['KASHIER_MAX_FAILURE_ATTEMPTS']['rules']);
        $this->assertStringEndsWith('/payment-gateway/webhooks/kashier', (string) $kashier['webhook_url']);
        $this->assertNotContains('stored-secret', $manager->values());
    }

    public function test_switching_mode_keeps_both_sets_and_mirrors_the_active_one(): void
    {
        $manager = $this->manager();
        $kashierId = $manager->gateways()['kashier']['id'];

        $result = $manager->save([
            'env' => [
                'KASHIER_ENVIRONMENT' => 'live',
                'KASHIER_LIVE_SECRET_KEY' => 'live-secret',
                'KASHIER_TEST_SECRET_KEY' => GatewaySettingsManager::SECRET_PLACEHOLDER,
                'KASHIER_TEST_MERCHANT_ID' => '',
                'KASHIER_ENABLE_3DS' => '0',
                'UNKNOWN_KEY' => 'ignored',
            ],
            'packages' => ['mo7mud/laravel-payment-gateway' => '1'],
            'gateways' => [$kashierId => '1'],
        ]);

        $environment = new EnvironmentManager($this->path);
        // The legacy values move into the test set instead of being lost.
        $this->assertSame('MID-1', $environment->get('KASHIER_TEST_MERCHANT_ID'));
        $this->assertSame('stored-secret', $environment->get('KASHIER_TEST_SECRET_KEY'));
        $this->assertSame('live', $environment->get('KASHIER_ENVIRONMENT'));
        $this->assertSame('live-secret', $environment->get('KASHIER_LIVE_SECRET_KEY'));
        // Legacy keys now mirror the live set; a live value that is missing is emptied, never left as the test one.
        $this->assertSame('MID-LIVE', $environment->get('KASHIER_MERCHANT_ID'));
        $this->assertSame('live-secret', $environment->get('KASHIER_SECRET_KEY'));
        $this->assertNull($environment->get('KASHIER_PAYMENT_API_KEY'));
        $this->assertSame('false', $environment->get('KASHIER_ENABLE_3DS'));
        $this->assertNull($environment->get('UNKNOWN_KEY'));
        $this->assertStringEndsWith('/payment-gateway/webhooks/kashier', (string) $environment->get('KASHIER_SERVER_WEBHOOK_URL'));
        $this->assertSame([], $result->warnings);

        $integrations = $this->app->make(PaymentIntegrationService::class);
        $this->assertTrue($integrations->isGatewayEnabled('kashier'));
        $this->assertFalse($integrations->isGatewayEnabled('fawaterk'));

        // Back to test: the stored test set becomes active again.
        $manager->save(['env' => ['KASHIER_ENVIRONMENT' => 'test']]);
        $environment = new EnvironmentManager($this->path);
        $this->assertSame('MID-1', $environment->get('KASHIER_MERCHANT_ID'));
        $this->assertSame('stored-secret', $environment->get('KASHIER_SECRET_KEY'));
        $this->assertSame('live-secret', $environment->get('KASHIER_LIVE_SECRET_KEY'));
    }

    public function test_invalid_input_is_rejected_without_writing(): void
    {
        $before = file_get_contents($this->path);

        try {
            $this->manager()->save(['env' => ['KASHIER_MAX_FAILURE_ATTEMPTS' => '99', 'KASHIER_LIVE_MERCHANT_ID' => 'MID-3']]);
            $this->fail('Out-of-range values must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('env.KASHIER_MAX_FAILURE_ATTEMPTS', $e->errors());
        }

        $this->assertSame($before, file_get_contents($this->path));
    }

    public function test_api_base_urls_are_never_editable(): void
    {
        config()->set('payment-gateway.admin.packages.mo7mud/laravel-payment-gateway.gateways.kashier.extra_env', [
            'KASHIER_LIVE_API_BASE_URL' => ['label' => 'API', 'type' => 'url'],
        ]);
        $manager = $this->manager();

        $this->assertArrayNotHasKey('KASHIER_LIVE_API_BASE_URL', $manager->fields());

        $manager->save(['env' => ['KASHIER_LIVE_API_BASE_URL' => 'https://attacker.example']]);
        $this->assertNull((new EnvironmentManager($this->path))->get('KASHIER_LIVE_API_BASE_URL'));
    }

    public function test_the_built_in_page_renders_both_credential_sets(): void
    {
        $this->actingAs($this->user(1))
            ->get('/payment-gateway/settings')
            ->assertOk()
            ->assertSee('name="env[KASHIER_TEST_MERCHANT_ID]"', false)
            ->assertSee('name="env[KASHIER_LIVE_SECRET_KEY]"', false)
            ->assertSee('name="env[KASHIER_ENVIRONMENT]"', false)
            ->assertSee('MID-LIVE')
            ->assertDontSee('stored-secret');
    }

    private function manager(): GatewaySettingsManager
    {
        return $this->app->make(GatewaySettingsManager::class);
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id, 'name' => 'Admin', 'email' => 'admin'.$id.'@example.test']);
    }
}
