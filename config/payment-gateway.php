<?php

declare(strict_types=1);

use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Support\PayloadRedactor;
use Mo7mud\LaravelPaymentGateway\Support\ReturnRedirect;

// Credentials are kept per mode (KASHIER_TEST_* / KASHIER_LIVE_*, FAWATERK_TEST_* /
// FAWATERK_LIVE_*); <PREFIX>_ENVIRONMENT picks the active set. The unprefixed keys
// (KASHIER_MERCHANT_ID, ...) are the fallback, so existing .env files keep working.
$paymentGatewayCredential = static function (string $prefix, string $name): string {
    $mode = strtolower((string) env($prefix.'_ENVIRONMENT', 'test')) === 'live' ? 'LIVE' : 'TEST';
    $value = env($prefix.'_'.$mode.'_'.$name);

    return is_string($value) && $value !== '' ? $value : (string) env($prefix.'_'.$name, '');
};

return [
    'default' => env('PAYMENT_GATEWAY', 'kashier'),

    // Keyed by gateway name. A gateway is only built when first used, so an
    // unconfigured gateway cannot break the others.
    'gateways' => [
        'kashier' => KashierGateway::class,
        'fawaterk' => FawaterkGateway::class,
    ],

    // Prefixed defaults: most applications already own a `payments` table.
    // Installations created before these defaults must keep their old names
    // (e.g. PAYMENT_GATEWAY_PAYMENTS_TABLE=payments). The migrations refuse to
    // touch a table with the configured name that the package did not create.
    'tables' => [
        'payments' => env('PAYMENT_GATEWAY_PAYMENTS_TABLE', 'gateway_payments'),
        'refunds' => env('PAYMENT_GATEWAY_REFUNDS_TABLE', 'gateway_payment_refunds'),
        'webhook_events' => env('PAYMENT_GATEWAY_WEBHOOK_EVENTS_TABLE', 'gateway_payment_webhook_events'),
    ],

    'logging' => [
        // null = the application's default channel.
        'channel' => env('PAYMENT_GATEWAY_LOG_CHANNEL'),
    ],

    // Public endpoints for gateway notifications and browser returns:
    //   POST {prefix}/webhooks/{gateway}/{type?}   (name: payment-gateway.webhook)
    //   GET|POST {prefix}/return/{gateway}          (name: payment-gateway.return)
    'routes' => [
        'enabled' => env('PAYMENT_GATEWAY_ROUTES_ENABLED', true),
        'prefix' => env('PAYMENT_GATEWAY_ROUTE_PREFIX', 'payment-gateway'),
        // Gateways send every notification from a few IP addresses, so the limit
        // is shared by all payments. Raise it for high volume.
        'middleware' => ['api', 'throttle:'.(int) env('PAYMENT_GATEWAY_WEBHOOK_RATE_LIMIT', 600).',1'],
    ],

    'return' => [
        // Where to send the customer after checkout. Placeholders: {reference},
        // {status}, {gateway}, {error}. Leave empty to get a JSON response.
        // Never fulfil orders on return.
        'redirect_url' => env('PAYMENT_GATEWAY_RETURN_REDIRECT_URL'),
        // Per-payment redirect: CreatePaymentRequest metadata['return_redirect_url']
        // (same placeholders). Its host must be listed here (comma-separated in
        // .env, exact match), otherwise createPayment() rejects it.
        'allowed_hosts' => ReturnRedirect::hostsFromString(env('PAYMENT_GATEWAY_RETURN_ALLOWED_HOSTS')),
        // When the customer returns to an open payment, ask the gateway API for
        // its status once before redirecting (the query string is never trusted).
        'sync_status' => env('PAYMENT_GATEWAY_RETURN_SYNC_STATUS', true),
        // At most one such status query per payment within this many seconds.
        'sync_lock_seconds' => 10,
    ],

    'webhooks' => [
        // HTTP status for already-processed notifications. Kashier documents 409.
        'duplicate_status' => [
            'kashier' => 409,
        ],
        'retention_days' => (int) env('PAYMENT_GATEWAY_WEBHOOK_RETENTION_DAYS', 90),
        // Removed from stored payloads (case-insensitive, any depth).
        'redact_keys' => PayloadRedactor::DEFAULT_KEYS,
    ],

    'payments' => [
        // An INITIATING payment older than this is treated as abandoned and may be retried.
        'creation_lock_seconds' => 120,
        // Merchant order id for creation retries: sprintf(format, reference,
        // attempt, random token). Keep the token (%3$s): it prevents collisions.
        'attempt_reference_format' => '%1$s-A%2$d-%3$s',
        // Minutes after the checkout expiry before an unpaid payment becomes EXPIRED.
        'expiry_grace_minutes' => 30,
        // A pending refund missing from the gateway refund list after this many
        // hours is flagged for review (never failed automatically).
        'refund_unmatched_alert_hours' => 24,
    ],

    'reconciliation' => [
        // Registers payments:reconcile (every 5 minutes) and payments:prune-webhooks
        // (daily) with the scheduler. The host still needs `schedule:run` in cron.
        'schedule' => env('PAYMENT_GATEWAY_SCHEDULE', true),
        'cron' => '*/5 * * * *',
        'limit' => 100,
        'stale_minutes' => 15,
        'max_age_days' => 30,
        'base_delay_seconds' => 300,
        'max_delay_seconds' => 21600,
    ],

    // The application confirms each PaymentSucceeded it handled with
    // PaymentService::markFulfilled(). payments:redispatch-unfulfilled raises
    // the event again for paid payments without that confirmation (a listener
    // failed, a queued job was lost), with back-off, up to max_dispatches times.
    // Scheduled with payments:reconcile (needs reconciliation.schedule).
    'fulfilment' => [
        'redispatch' => env('PAYMENT_GATEWAY_REDISPATCH_UNFULFILLED', true),
        'cron' => '*/5 * * * *',
        'older_than_minutes' => 10,
        'limit' => 100,
        'max_dispatches' => 10,
        'base_delay_seconds' => 300,
        'max_delay_seconds' => 21600,
    ],

    'admin' => [
        // Off by default. When on, a Gate ability is required (fails closed).
        'enabled' => env('PAYMENT_GATEWAY_ADMIN_ENABLED', false),
        'permission' => env('PAYMENT_GATEWAY_ADMIN_PERMISSION'),
        // Block new payments on gateways switched off in the admin page.
        // null = follow admin.enabled.
        'enforce_gateway_toggles' => env('PAYMENT_GATEWAY_ENFORCE_TOGGLES'),
        'middleware' => ['web', 'auth'],
        'route_prefix' => 'payment-gateway/settings',
        'layout' => 'layouts.app',
        'title_section' => 'page-title',
        'content_section' => 'main',
        // API base URLs are deliberately not editable here: changing them would
        // send credentials to another host. Set them in the deployment .env.
        // Any field whose key contains BASE_URL is ignored even if added below.
        // label, display_name and select options are translation keys
        // (lang/*/admin.php); a value without a translation is shown as is.
        'packages' => [
            'mo7mud/laravel-payment-gateway' => [
                'display_name' => 'payment-gateway::admin.packages.core',
                'gateways' => [
                    'fawaterk' => [
                        'display_name' => 'payment-gateway::admin.gateways.fawaterk',
                        'sort_order' => 20,
                        // test/live switch: picks the active credential set; the other set stays saved.
                        'mode' => [
                            'env' => 'FAWATERK_ENVIRONMENT', 'label' => 'payment-gateway::admin.fields.environment', 'default' => 'test',
                            'options' => ['test' => 'payment-gateway::admin.modes.test', 'live' => 'payment-gateway::admin.modes.live'],
                        ],
                        // One value per mode. legacy_env mirrors the active value for older configs.
                        'credentials' => [
                            'client_id' => [
                                'label' => 'payment-gateway::admin.fields.client_id', 'type' => 'text', 'legacy_env' => 'FAWATERK_CLIENT_ID',
                                'env' => ['test' => 'FAWATERK_TEST_CLIENT_ID', 'live' => 'FAWATERK_LIVE_CLIENT_ID'],
                            ],
                            'client_secret' => [
                                'label' => 'payment-gateway::admin.fields.client_secret', 'type' => 'secret', 'legacy_env' => 'FAWATERK_CLIENT_SECRET',
                                'env' => ['test' => 'FAWATERK_TEST_CLIENT_SECRET', 'live' => 'FAWATERK_LIVE_CLIENT_SECRET'],
                            ],
                            'vendor_api_key' => [
                                'label' => 'payment-gateway::admin.fields.vendor_api_key', 'type' => 'secret', 'legacy_env' => 'FAWATERK_VENDOR_API_KEY',
                                'env' => ['test' => 'FAWATERK_TEST_VENDOR_API_KEY', 'live' => 'FAWATERK_LIVE_VENDOR_API_KEY'],
                            ],
                        ],
                        'env' => [
                            'FAWATERK_SUCCESS_URL' => ['label' => 'payment-gateway::admin.fields.success_url', 'type' => 'url'],
                            'FAWATERK_FAIL_URL' => ['label' => 'payment-gateway::admin.fields.fail_url', 'type' => 'url'],
                            'FAWATERK_PENDING_URL' => ['label' => 'payment-gateway::admin.fields.pending_url', 'type' => 'url'],
                            'FAWATERK_BACK_URL' => ['label' => 'payment-gateway::admin.fields.back_url', 'type' => 'url'],
                            'FAWATERK_TIMEOUT' => ['label' => 'payment-gateway::admin.fields.http_timeout', 'type' => 'number', 'min' => 5, 'max' => 60],
                        ],
                        'extra_env' => [],
                        'routes' => [
                            'webhook' => [
                                'env' => 'FAWATERK_WEBHOOK_URL',
                                'candidates' => [
                                    ['route' => 'fawaterk.webhook'],
                                    ['route' => 'payment-gateway.webhook', 'parameters' => ['gateway' => 'fawaterk', 'type' => 'paid_json']],
                                ],
                            ],
                            'return' => [
                                'env' => 'FAWATERK_SUCCESS_URL',
                                'candidates' => [
                                    ['route' => 'fawaterk.success'],
                                    ['route' => 'payment-gateway.return', 'parameters' => ['gateway' => 'fawaterk']],
                                ],
                            ],
                        ],
                    ],
                    'kashier' => [
                        'display_name' => 'payment-gateway::admin.gateways.kashier',
                        'sort_order' => 10,
                        'mode' => [
                            'env' => 'KASHIER_ENVIRONMENT', 'label' => 'payment-gateway::admin.fields.environment', 'default' => 'test',
                            'options' => ['test' => 'payment-gateway::admin.modes.test', 'live' => 'payment-gateway::admin.modes.live'],
                        ],
                        'credentials' => [
                            'merchant_id' => [
                                'label' => 'payment-gateway::admin.fields.merchant_id', 'type' => 'text', 'pattern' => '/^[A-Za-z0-9_-]+$/', 'legacy_env' => 'KASHIER_MERCHANT_ID',
                                'env' => ['test' => 'KASHIER_TEST_MERCHANT_ID', 'live' => 'KASHIER_LIVE_MERCHANT_ID'],
                            ],
                            'payment_api_key' => [
                                'label' => 'payment-gateway::admin.fields.payment_api_key', 'type' => 'secret', 'legacy_env' => 'KASHIER_PAYMENT_API_KEY',
                                'env' => ['test' => 'KASHIER_TEST_PAYMENT_API_KEY', 'live' => 'KASHIER_LIVE_PAYMENT_API_KEY'],
                            ],
                            'secret_key' => [
                                'label' => 'payment-gateway::admin.fields.secret_key', 'type' => 'secret', 'legacy_env' => 'KASHIER_SECRET_KEY',
                                'env' => ['test' => 'KASHIER_TEST_SECRET_KEY', 'live' => 'KASHIER_LIVE_SECRET_KEY'],
                            ],
                        ],
                        'env' => [
                            'KASHIER_ALLOWED_METHODS' => ['label' => 'payment-gateway::admin.fields.allowed_methods', 'type' => 'text', 'pattern' => '/^[a-z_]+(,[a-z_]+)*$/'],
                            'KASHIER_PAYMENT_TYPE' => ['label' => 'payment-gateway::admin.fields.payment_type', 'type' => 'select', 'options' => ['credit' => 'payment-gateway::admin.options.credit']],
                            'KASHIER_DEFAULT_METHOD' => ['label' => 'payment-gateway::admin.fields.default_method', 'type' => 'text', 'pattern' => '/^[a-z_]+$/'],
                            'KASHIER_REDIRECT_METHOD' => ['label' => 'payment-gateway::admin.fields.redirect_method', 'type' => 'select', 'options' => ['get' => 'payment-gateway::admin.options.get', 'post' => 'payment-gateway::admin.options.post']],
                            'KASHIER_DISPLAY' => ['label' => 'payment-gateway::admin.fields.display_language', 'type' => 'select', 'options' => ['ar' => 'payment-gateway::admin.options.arabic', 'en' => 'payment-gateway::admin.options.english']],
                            'KASHIER_ENABLE_3DS' => ['label' => 'payment-gateway::admin.fields.enable_3ds', 'type' => 'boolean'],
                            'KASHIER_FAILURE_REDIRECT' => ['label' => 'payment-gateway::admin.fields.failure_redirect', 'type' => 'boolean'],
                            'KASHIER_MAX_FAILURE_ATTEMPTS' => ['label' => 'payment-gateway::admin.fields.max_failure_attempts', 'type' => 'number', 'min' => 1, 'max' => 10],
                            'KASHIER_EXPIRE_MINUTES' => ['label' => 'payment-gateway::admin.fields.expire_minutes', 'type' => 'number', 'min' => 5, 'max' => 1440],
                            'KASHIER_HTTP_TIMEOUT' => ['label' => 'payment-gateway::admin.fields.http_timeout', 'type' => 'number', 'min' => 5, 'max' => 60],
                        ],
                        'extra_env' => [],
                        'routes' => [
                            'webhook' => [
                                'env' => 'KASHIER_SERVER_WEBHOOK_URL',
                                'candidates' => [
                                    ['route' => 'kashier.webhook'],
                                    ['route' => 'payment-gateway.webhook', 'parameters' => ['gateway' => 'kashier']],
                                ],
                            ],
                            'return' => [
                                'env' => 'KASHIER_RETURN_URL',
                                'candidates' => [
                                    ['route' => 'kashier.return'],
                                    ['route' => 'payment-gateway.return', 'parameters' => ['gateway' => 'kashier']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'fawaterk' => [
        'environment' => env('FAWATERK_ENVIRONMENT', 'test'),
        'api_base_url' => env('FAWATERK_API_BASE_URL', ''),
        'api_base_urls' => [
            'test' => env('FAWATERK_TEST_API_BASE_URL', 'https://staging.fawaterk.com'),
            'live' => env('FAWATERK_LIVE_API_BASE_URL', 'https://app.fawaterk.com'),
        ],
        'client_id' => $paymentGatewayCredential('FAWATERK', 'CLIENT_ID'),
        'client_secret' => $paymentGatewayCredential('FAWATERK', 'CLIENT_SECRET'),
        'vendor_api_key' => $paymentGatewayCredential('FAWATERK', 'VENDOR_API_KEY'),
        'success_url' => env('FAWATERK_SUCCESS_URL', ''),
        'fail_url' => env('FAWATERK_FAIL_URL', ''),
        'pending_url' => env('FAWATERK_PENDING_URL', ''),
        'back_url' => env('FAWATERK_BACK_URL', ''),
        'webhook_url' => env('FAWATERK_WEBHOOK_URL', ''),
        'timeout' => (int) env('FAWATERK_TIMEOUT', 15),
        'connect_timeout' => (int) env('FAWATERK_CONNECT_TIMEOUT', 5),
        // Metadata keys (besides payment_reference) sent to Fawaterk in pay_load.
        'forward_metadata_keys' => [],
        // Application refund reason => reason text Fawaterk accepts. Unknown
        // reasons are sent as "Other" with the original text as comment.
        'refund_reason_map' => [],
        // Refunds are confirmed from POST /api/v3/refund/index (10 per page).
        'refund_list_max_pages' => (int) env('FAWATERK_REFUND_LIST_MAX_PAGES', 20),
        // Fawaterk refund status => succeeded|failed|pending (merged with built-in defaults).
        'refund_status_map' => [],
        // refundable_type values that never belong to a transaction refund.
        'refund_excluded_types' => ['invoice'],
    ],

    'kashier' => [
        'environment' => env('KASHIER_ENVIRONMENT', 'test'),
        'merchant_id' => $paymentGatewayCredential('KASHIER', 'MERCHANT_ID'),
        'payment_api_key' => $paymentGatewayCredential('KASHIER', 'PAYMENT_API_KEY'),
        'secret_key' => $paymentGatewayCredential('KASHIER', 'SECRET_KEY'),
        'server_webhook' => env('KASHIER_SERVER_WEBHOOK_URL', ''),
        'return_url' => env('KASHIER_RETURN_URL', ''),
        'allowed_methods' => env('KASHIER_ALLOWED_METHODS', 'card,wallet'),
        'payment_type' => env('KASHIER_PAYMENT_TYPE', 'credit'),
        'default_method' => env('KASHIER_DEFAULT_METHOD', 'card'),
        'redirect_method' => env('KASHIER_REDIRECT_METHOD'),
        'description' => env('KASHIER_DESCRIPTION'),
        'display' => env('KASHIER_DISPLAY', 'en'),
        'enable_3ds' => (bool) env('KASHIER_ENABLE_3DS', true),
        'failure_redirect' => (bool) env('KASHIER_FAILURE_REDIRECT', false),
        'max_failure_attempts' => (int) env('KASHIER_MAX_FAILURE_ATTEMPTS', 3),
        'expire_minutes' => (int) env('KASHIER_EXPIRE_MINUTES', 30),
        'http_timeout' => (int) env('KASHIER_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('KASHIER_CONNECT_TIMEOUT', 5),
        'read_retries' => 2,
        'reconcile_max_pages' => 5,
        // Metadata keys (besides payment_reference) sent to Kashier in metaData.
        'forward_metadata_keys' => [],
        // Webhooks that do not sign these fields are rejected.
        'required_signed_keys' => KashierSignatureVerifier::DEFAULT_REQUIRED_KEYS,
        'api_base_urls' => [
            'test' => env('KASHIER_TEST_API_BASE_URL', 'https://test-api.kashier.io'),
            'live' => env('KASHIER_LIVE_API_BASE_URL', 'https://api.kashier.io'),
        ],
        'fep_base_urls' => [
            'test' => env('KASHIER_TEST_FEP_BASE_URL', 'https://test-fep.kashier.io'),
            'live' => env('KASHIER_LIVE_FEP_BASE_URL', 'https://fep.kashier.io'),
        ],
    ],
];
