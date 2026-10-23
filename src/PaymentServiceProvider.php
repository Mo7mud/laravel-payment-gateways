<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Mo7mud\LaravelPaymentGateway\Admin\EnvironmentManager;
use Mo7mud\LaravelPaymentGateway\Admin\PaymentIntegrationService;
use Mo7mud\LaravelPaymentGateway\Console\PaymentReconciliationCommand;
use Mo7mud\LaravelPaymentGateway\Console\PruneWebhookEventsCommand;
use Mo7mud\LaravelPaymentGateway\Console\RedispatchUnfulfilledCommand;
use Mo7mud\LaravelPaymentGateway\Console\ResolveRefundCommand;
use Mo7mud\LaravelPaymentGateway\Console\SyncIntegrationsCommand;
use Mo7mud\LaravelPaymentGateway\Contracts\TransactionRunnerInterface;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\Exceptions\FawaterkApiException;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkClient;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkClientInterface;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkMapper;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkTokenManager;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierClient;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierClientInterface;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use Mo7mud\LaravelPaymentGateway\Infrastructure\DatabaseTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\EloquentPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\EloquentRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\EloquentWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\PaymentRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Repositories\RefundRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Repositories\WebhookEventRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentReconciliationService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Support\PayloadRedactor;
use Psr\Log\LoggerInterface;

final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payment-gateway.php', 'payment-gateway');

        $this->app->singleton(PaymentRepositoryInterface::class, EloquentPaymentRepository::class);
        $this->app->singleton(WebhookEventRepositoryInterface::class, EloquentWebhookEventRepository::class);
        $this->app->singleton(RefundRepositoryInterface::class, EloquentRefundRepository::class);
        $this->app->singleton(TransactionRunnerInterface::class, DatabaseTransactionRunner::class);
        $this->app->singleton(PaymentStateManager::class);
        $this->app->singleton(PayloadRedactor::class, static fn (): PayloadRedactor => new PayloadRedactor(
            (array) config('payment-gateway.webhooks.redact_keys', PayloadRedactor::DEFAULT_KEYS),
        ));

        $this->registerKashier();
        $this->registerFawaterk();

        $this->app->singleton(EnvironmentManager::class, static fn (): EnvironmentManager => new EnvironmentManager());
        $this->app->singleton(PaymentIntegrationService::class, static fn (Application $app): PaymentIntegrationService => new PaymentIntegrationService(
            $app->make('cache')->store(),
        ));

        $this->app->singleton(GatewayManager::class, static fn (Application $app): GatewayManager => new GatewayManager(
            (array) config('payment-gateway.gateways', []),
            static fn (string $class) => $app->make($class),
        ));

        $this->app->singleton(PaymentService::class, fn (Application $app): PaymentService => new PaymentService(
            gateways: $app->make(GatewayManager::class),
            payments: $app->make(PaymentRepositoryInterface::class),
            states: $app->make(PaymentStateManager::class),
            webhookEvents: $app->make(WebhookEventRepositoryInterface::class),
            transactions: $app->make(TransactionRunnerInterface::class),
            refunds: $app->make(RefundRepositoryInterface::class),
            events: $app->make(Dispatcher::class),
            logger: $this->logger($app),
            availability: $this->enforceGatewayToggles() ? $app->make(PaymentIntegrationService::class) : null,
            redactor: $app->make(PayloadRedactor::class),
            options: [
                'creation_lock_seconds' => (int) config('payment-gateway.payments.creation_lock_seconds', 120),
                'attempt_reference_format' => (string) config('payment-gateway.payments.attempt_reference_format', '%1$s-A%2$d-%3$s'),
                'refund_unmatched_alert_hours' => (int) config('payment-gateway.payments.refund_unmatched_alert_hours', 24),
                'expiry_grace_minutes' => (int) config('payment-gateway.payments.expiry_grace_minutes', 30),
                'reconcile_base_delay_seconds' => (int) config('payment-gateway.reconciliation.base_delay_seconds', 300),
                'reconcile_max_delay_seconds' => (int) config('payment-gateway.reconciliation.max_delay_seconds', 21600),
                'fulfilment_max_dispatches' => (int) config('payment-gateway.fulfilment.max_dispatches', 10),
                'fulfilment_base_delay_seconds' => (int) config('payment-gateway.fulfilment.base_delay_seconds', 300),
                'fulfilment_max_delay_seconds' => (int) config('payment-gateway.fulfilment.max_delay_seconds', 21600),
                'return_sync_status' => $this->flag('payment-gateway.return.sync_status', true),
                'return_sync_lock_seconds' => (int) config('payment-gateway.return.sync_lock_seconds', 10),
                'return_allowed_hosts' => (array) config('payment-gateway.return.allowed_hosts', []),
            ],
            cache: $app->make('cache')->store(),
        ));

        $this->app->singleton(PaymentReconciliationService::class, static fn (Application $app): PaymentReconciliationService => new PaymentReconciliationService(
            $app->make(PaymentRepositoryInterface::class),
            $app->make(PaymentService::class),
            $app->make(RefundRepositoryInterface::class),
            (int) config('payment-gateway.reconciliation.max_age_days', 30),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PaymentReconciliationCommand::class,
                PruneWebhookEventsCommand::class,
                RedispatchUnfulfilledCommand::class,
                SyncIntegrationsCommand::class,
                ResolveRefundCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/payment-gateway.php' => config_path('payment-gateway.php'),
            ], 'payment-gateway-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'payment-gateway-migrations');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'payment-gateway');

        if ($this->flag('payment-gateway.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
        }

        // The settings page writes credentials to .env; it only exists when
        // explicitly enabled, and then requires a Gate ability.
        if ($this->flag('payment-gateway.admin.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/admin.php');
        }

        if ($this->flag('payment-gateway.reconciliation.schedule', true)) {
            $redispatch = $this->flag('payment-gateway.fulfilment.redispatch', true);

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($redispatch): void {
                $schedule->command('payments:reconcile')
                    ->cron((string) config('payment-gateway.reconciliation.cron', '*/5 * * * *'))
                    ->withoutOverlapping(30)
                    ->onOneServer();

                $schedule->command('payments:prune-webhooks')
                    ->daily()
                    ->withoutOverlapping()
                    ->onOneServer();

                if ($redispatch) {
                    $schedule->command('payments:redispatch-unfulfilled')
                        ->cron((string) config('payment-gateway.fulfilment.cron', '*/5 * * * *'))
                        ->withoutOverlapping(30)
                        ->onOneServer();
                }
            });
        }
    }

    private function registerKashier(): void
    {
        $this->app->singleton(KashierStatusMapper::class);

        $this->app->singleton(KashierClientInterface::class, static function (Application $app): KashierClientInterface {
            $config = (array) config('payment-gateway.kashier', []);
            $environment = (string) ($config['environment'] ?? 'test');

            return new KashierClient(
                http: $app->make(HttpFactory::class),
                secretKey: (string) ($config['secret_key'] ?? ''),
                paymentApiKey: (string) ($config['payment_api_key'] ?? ''),
                apiBaseUrl: (string) ($config['api_base_urls'][$environment] ?? ''),
                fepBaseUrl: (string) ($config['fep_base_urls'][$environment] ?? ''),
                timeout: max(1, (int) ($config['http_timeout'] ?? 15)),
                connectTimeout: max(1, (int) ($config['connect_timeout'] ?? 5)),
                readRetries: max(0, (int) ($config['read_retries'] ?? 2)),
            );
        });

        $this->app->singleton(KashierSignatureVerifier::class, static fn (): KashierSignatureVerifier => new KashierSignatureVerifier(
            (string) config('payment-gateway.kashier.payment_api_key', ''),
            array_values((array) config('payment-gateway.kashier.required_signed_keys', KashierSignatureVerifier::DEFAULT_REQUIRED_KEYS)),
        ));

        $this->app->singleton(KashierGateway::class, fn (Application $app): KashierGateway => new KashierGateway(
            client: $app->make(KashierClientInterface::class),
            signatures: $app->make(KashierSignatureVerifier::class),
            statuses: $app->make(KashierStatusMapper::class),
            config: (array) config('payment-gateway.kashier', []),
            logger: $this->logger($app),
        ));
    }

    private function registerFawaterk(): void
    {
        $this->app->singleton(FawaterkMapper::class);

        $this->app->singleton(FawaterkTokenManager::class, static function (Application $app): FawaterkTokenManager {
            $config = (array) config('payment-gateway.fawaterk', []);
            $environment = (string) ($config['environment'] ?? 'test');
            $apiBaseUrl = (string) (($config['api_base_url'] ?? '') ?: ($config['api_base_urls'][$environment] ?? ''));
            $http = $app->make(HttpFactory::class);
            $timeout = max(1, (int) ($config['timeout'] ?? 15));
            $connectTimeout = max(1, (int) ($config['connect_timeout'] ?? 5));

            return new FawaterkTokenManager(
                cache: $app->make('cache')->store(),
                clientId: (string) ($config['client_id'] ?? ''),
                clientSecret: (string) ($config['client_secret'] ?? ''),
                environment: $environment,
                apiBaseUrl: $apiBaseUrl,
                tokenRequester: static function (string $grantType, array $payload) use ($http, $apiBaseUrl, $timeout, $connectTimeout): array {
                    try {
                        $response = $http
                            ->connectTimeout($connectTimeout)
                            ->timeout($timeout)
                            ->asJson()
                            ->acceptJson()
                            ->post(rtrim($apiBaseUrl, '/') . '/oauth/token', $payload);
                    } catch (\Throwable $e) {
                        throw new FawaterkApiException('Fawaterk OAuth token request failed: ' . $e::class);
                    }

                    $json = $response->json();
                    if (!is_array($json)) {
                        $json = ['raw' => mb_substr((string) $response->body(), 0, 2000)];
                    }

                    if ($response->failed()) {
                        $message = $json['message'] ?? $json['error_description'] ?? $json['error'] ?? 'Fawaterk OAuth token request failed.';
                        throw new FawaterkApiException(mb_substr(is_string($message) ? $message : 'Fawaterk OAuth token request failed.', 0, 300), $response->status(), $json);
                    }

                    return $json;
                },
            );
        });

        $this->app->singleton(FawaterkClientInterface::class, static function (Application $app): FawaterkClientInterface {
            $config = (array) config('payment-gateway.fawaterk', []);
            $environment = (string) ($config['environment'] ?? 'test');

            return new FawaterkClient(
                http: $app->make(HttpFactory::class),
                tokens: $app->make(FawaterkTokenManager::class),
                apiBaseUrl: (string) (($config['api_base_url'] ?? '') ?: ($config['api_base_urls'][$environment] ?? '')),
                timeout: max(1, (int) ($config['timeout'] ?? 15)),
                connectTimeout: max(1, (int) ($config['connect_timeout'] ?? 5)),
            );
        });

        $this->app->singleton(FawaterkSignatureVerifier::class, static fn (): FawaterkSignatureVerifier => new FawaterkSignatureVerifier(
            (string) config('payment-gateway.fawaterk.vendor_api_key', ''),
        ));

        $this->app->singleton(FawaterkGateway::class, static fn (Application $app): FawaterkGateway => new FawaterkGateway(
            client: $app->make(FawaterkClientInterface::class),
            mapper: $app->make(FawaterkMapper::class),
            signatures: $app->make(FawaterkSignatureVerifier::class),
            config: (array) config('payment-gateway.fawaterk', []),
            cache: $app->make('cache')->store(),
        ));
    }

    private function logger(Application $app): LoggerInterface
    {
        $channel = config('payment-gateway.logging.channel');
        $log = $app->make('log');

        return is_string($channel) && $channel !== '' ? $log->channel($channel) : $log->channel();
    }

    private function enforceGatewayToggles(): bool
    {
        $value = config('payment-gateway.admin.enforce_gateway_toggles');
        if ($value === null || $value === '') {
            return $this->flag('payment-gateway.admin.enabled', false);
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function flag(string $key, bool $default): bool
    {
        return filter_var(config($key, $default), FILTER_VALIDATE_BOOLEAN);
    }
}
