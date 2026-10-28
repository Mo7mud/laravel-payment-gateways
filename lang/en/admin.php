<?php

declare(strict_types=1);

return [
    'title' => 'Payment Gateway Package Settings',
    'package_hint' => 'Managed by the reusable payment package',
    'enable_package' => 'Enable Package',
    'enable_gateway' => 'Enable',
    'enabled' => 'Enabled',
    'credentials' => [
        'test' => 'Test credentials',
        'live' => 'Live credentials',
    ],
    'settings' => 'Settings',
    'webhook_url' => 'Webhook URL',
    'return_url' => 'Return URL',
    'copy' => 'Copy',
    'save' => 'Save and Apply',
    'secret_placeholder' => 'Leave blank to keep current value',
    'secret_configured' => 'A value is already configured. Leave this field empty to keep it unchanged.',
    // Validation attribute of a test/live credential.
    'attribute_with_mode' => ':label (:mode)',

    'packages' => [
        'core' => 'Payment gateways',
    ],

    'gateways' => [
        'kashier' => 'Kashier',
        'fawaterk' => 'Fawaterk',
    ],

    'modes' => [
        'test' => 'Test',
        'live' => 'Live',
    ],

    'fields' => [
        'environment' => 'Environment',
        'client_id' => 'OAuth Client ID',
        'client_secret' => 'OAuth Client Secret',
        'vendor_api_key' => 'Vendor API Key (Webhook HMAC)',
        'success_url' => 'Success URL',
        'fail_url' => 'Fail URL',
        'pending_url' => 'Pending URL',
        'back_url' => 'Back URL',
        'http_timeout' => 'HTTP Timeout (seconds)',
        'merchant_id' => 'Merchant ID',
        'payment_api_key' => 'Payment API Key',
        'secret_key' => 'Secret Key',
        'allowed_methods' => 'Allowed Methods',
        'payment_type' => 'Payment Type',
        'default_method' => 'Default Method',
        'redirect_method' => 'Redirect Method',
        'display_language' => 'Display Language',
        'enable_3ds' => 'Enable 3DS',
        'failure_redirect' => 'Failure Redirect',
        'max_failure_attempts' => 'Max Failure Attempts',
        'expire_minutes' => 'Checkout Expiry (minutes)',
    ],

    'options' => [
        'credit' => 'Credit',
        'get' => 'GET',
        'post' => 'POST',
        'arabic' => 'Arabic',
        'english' => 'English',
    ],

    'messages' => [
        'saved' => 'Payment gateway settings saved.',
        'invalid' => 'Settings were not saved. Fix the following and try again:',
        'locked' => 'Payment gateway settings are locked. Set PAYMENT_GATEWAY_ADMIN_PERMISSION to a Gate ability.',
    ],

    'warnings' => [
        'config_cache_cleared' => 'Configuration cache was cleared. Run "php artisan config:cache" to rebuild it.',
        'config_cache_failed' => 'Settings were saved, but the configuration cache could not be cleared. Run "php artisan config:clear".',
        'queue_restart' => 'Settings were saved. Restart queue workers so they use the new values.',
    ],
];
