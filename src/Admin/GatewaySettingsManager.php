<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Admin;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mo7mud\LaravelPaymentGateway\Models\PaymentIntegrationGateway;
use Mo7mud\LaravelPaymentGateway\Models\PaymentIntegrationPackage;
use Mo7mud\LaravelPaymentGateway\Support\Translation;
use Throwable;

/**
 * UI-agnostic logic behind the gateway settings page: field definitions,
 * current values (secrets masked), test/live credential sets, webhook/return
 * URLs, package and gateway switches, saving and access control. Any admin
 * panel (Blade, Filament, ...) renders its own form from this data; nothing
 * here returns a view or a response. Labels, options, display names and
 * messages come translated for the current locale.
 */
final class GatewaySettingsManager
{
    /** Returned instead of real secret values; posting it back keeps the stored secret. */
    public const SECRET_PLACEHOLDER = '__configured__';

    /** @var list<string> */
    public const MODES = ['test', 'live'];

    /** Field groups: the mode switch, one group per mode, and shared settings. */
    public const GROUP_MODE = 'mode';

    public const GROUP_SETTINGS = 'settings';

    /** Keys matching this are never editable: they decide where credentials are sent. */
    private const LOCKED_KEY_PATTERN = '/BASE_URL/';

    public function __construct(
        private readonly PaymentIntegrationService $integrations,
        private readonly EnvironmentManager $environment,
    ) {}

    /** Fails closed: without a configured Gate ability nobody has access. */
    public function canAccess(?Authenticatable $user = null): bool
    {
        $permission = $this->permission();
        if ($permission === null) {
            return false;
        }

        return $user === null ? Gate::allows($permission) : Gate::forUser($user)->allows($permission);
    }

    /** Aborts with 403 when the settings are locked or the Gate denies access. */
    public function authorize(?Authenticatable $user = null): void
    {
        $permission = $this->permission();
        if ($permission === null) {
            abort(403, Translation::get('payment-gateway::admin.messages.locked'));
        }

        if ($user === null) {
            Gate::authorize($permission);

            return;
        }

        Gate::forUser($user)->authorize($permission);
    }

    /**
     * Gateways ready to render as tabs/sections: switch state, active mode,
     * test and live credentials, shared settings and the URLs to copy into
     * the gateway dashboard. Fields carry their current (masked) value.
     *
     * @return array<string, array{package: string, gateway: string, display_name: string, sort_order: int, id: int|null, enabled: bool, package_enabled: bool, mode: array<string, mixed>|null, credentials: array<string, array<string, array<string, mixed>>>, settings: array<string, array<string, mixed>>, webhook_url: string|null, return_url: string|null}>
     */
    public function gateways(): array
    {
        $fields = $this->fields();
        $switches = $this->gatewaySwitches();
        $gateways = [];

        foreach ($this->packageConfigs() as $packageKey => $packageConfig) {
            foreach ((array) ($packageConfig['gateways'] ?? []) as $gatewayKey => $gatewayConfig) {
                $gatewayKey = (string) $gatewayKey;
                $gatewayConfig = (array) $gatewayConfig;
                $own = array_filter($fields, static fn (array $field): bool => $field['gateway'] === $gatewayKey);

                $mode = null;
                $credentials = array_fill_keys(self::MODES, []);
                $settings = [];
                foreach ($own as $key => $field) {
                    if ($field['group'] === self::GROUP_MODE) {
                        $mode = $field + ['current' => (string) $field['value']];
                    } elseif ($field['group'] === self::GROUP_SETTINGS) {
                        $settings[$key] = $field;
                    } else {
                        $credentials[$field['group']][$key] = $field;
                    }
                }

                $switch = $switches[$gatewayKey] ?? null;
                $gateways[$gatewayKey] = [
                    'package' => (string) $packageKey,
                    'gateway' => $gatewayKey,
                    'display_name' => Translation::get((string) ($gatewayConfig['display_name'] ?? ucfirst($gatewayKey))),
                    'sort_order' => (int) ($gatewayConfig['sort_order'] ?? 0),
                    'id' => $switch['id'] ?? null,
                    'enabled' => (bool) ($switch['enabled'] ?? false),
                    'package_enabled' => (bool) ($switch['package_enabled'] ?? false),
                    'mode' => $mode,
                    'credentials' => $credentials,
                    'settings' => $settings,
                    'webhook_url' => $this->routeUrl($gatewayConfig['routes']['webhook'] ?? null),
                    'return_url' => $this->routeUrl($gatewayConfig['routes']['return'] ?? null),
                ];
            }
        }

        return $gateways;
    }

    /**
     * Every editable field keyed by its environment variable. `group` is
     * "mode", "test", "live" or "settings"; `name` is the credential name
     * (merchant_id, ...) for test/live fields.
     *
     * @return array<string, array{key: string, name: string|null, package: string, gateway: string, group: string, label: string, type: string, secret: bool, options: array<array-key, string>, min: int|null, max: int|null, pattern: string|null, rules: array<int, mixed>, value: mixed}>
     */
    public function fields(): array
    {
        $values = $this->values();
        $fields = [];

        foreach ($this->definitions() as $key => $entry) {
            $field = $entry['field'];
            $type = (string) ($field['type'] ?? 'text');

            $fields[$key] = [
                'key' => $key,
                'name' => $entry['name'],
                'package' => $entry['package'],
                'gateway' => $entry['gateway'],
                'group' => $entry['group'],
                'label' => Translation::get((string) ($field['label'] ?? $key)),
                'type' => $type,
                'secret' => $type === 'secret',
                'options' => $type === 'select' ? array_map(static fn ($option): string => Translation::get((string) $option), (array) ($field['options'] ?? [])) : [],
                'min' => $type === 'number' ? (int) ($field['min'] ?? 0) : null,
                'max' => $type === 'number' ? (int) ($field['max'] ?? 100000) : null,
                'pattern' => isset($field['pattern']) ? (string) $field['pattern'] : null,
                'rules' => $this->rulesFor($field),
                'value' => $values[$key] ?? null,
            ];
        }

        return $fields;
    }

    /**
     * Current values keyed by environment variable. Secrets are never returned:
     * a configured secret is SECRET_PLACEHOLDER, a missing one null. Until the
     * first save, the active mode shows the legacy unprefixed keys.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $modes = $this->modes();
        $values = [];

        foreach ($this->definitions() as $key => $entry) {
            if ($entry['group'] === self::GROUP_MODE) {
                $values[$key] = $this->currentMode($modes[$entry['gateway']]);

                continue;
            }

            $value = $this->environment->get($key);
            $activeMode = isset($modes[$entry['gateway']]) ? $this->currentMode($modes[$entry['gateway']]) : null;
            if (blank($value) && $entry['legacy'] !== null && $entry['group'] === $activeMode) {
                $value = $this->environment->get($entry['legacy']);
            }

            $values[$key] = ($entry['field']['type'] ?? 'text') === 'secret'
                ? (filled($value) ? self::SECRET_PLACEHOLDER : null)
                : $value;
        }

        return $values;
    }

    /**
     * Package and gateway switches. Gateway switches are saved by row `id`.
     *
     * @return list<array{package: string, display_name: string, enabled: bool, gateways: list<array{id: int, gateway: string, display_name: string, enabled: bool, sort_order: int}>}>
     */
    public function packages(): array
    {
        return $this->integrations->packages()
            ->map(static fn (PaymentIntegrationPackage $package): array => [
                'package' => (string) $package->package,
                'display_name' => Translation::get((string) $package->display_name),
                'enabled' => (bool) $package->enabled,
                'gateways' => $package->gateways
                    ->map(static fn (PaymentIntegrationGateway $gateway): array => [
                        'id' => (int) $gateway->id,
                        'gateway' => (string) $gateway->gateway,
                        'display_name' => Translation::get((string) $gateway->display_name),
                        'enabled' => (bool) $gateway->enabled,
                        'sort_order' => (int) $gateway->sort_order,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Validation rules for the input accepted by save().
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'packages' => ['nullable', 'array'],
            'packages.*' => ['nullable', 'boolean'],
            'gateways' => ['nullable', 'array'],
            'gateways.*' => ['nullable', 'boolean'],
            'env' => ['nullable', 'array'],
        ];

        foreach ($this->definitions() as $key => $entry) {
            $rules['env.'.$key] = $this->rulesFor($entry['field']);
        }

        return $rules;
    }

    /**
     * Validates and stores the settings. Input shape:
     * `['env' => [KEY => value], 'packages' => [package => bool], 'gateways' => [id => bool]]`.
     * Blank values and SECRET_PLACEHOLDER never overwrite a stored value.
     * Switching mode keeps both credential sets.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context  extra fields for the audit log (user id, ip, ...)
     *
     * @throws ValidationException
     */
    public function save(array $input, array $context = []): SettingsSaveResult
    {
        $definitions = $this->definitions();
        $validated = Validator::make($input, $this->rules(), [], $this->attributes($definitions))->validate();

        $environmentValues = [];
        foreach ((array) ($validated['env'] ?? []) as $key => $value) {
            if (! isset($definitions[$key])) {
                continue;
            }

            $normalized = $this->normalize($definitions[$key]['field'], $value);
            if ($normalized !== null) {
                $environmentValues[$key] = $normalized;
            }
        }

        foreach ($this->legacyCredentialValues($environmentValues) as $key => $value) {
            $environmentValues[$key] = $value;
        }

        foreach ($this->generatedEnvironmentValues() as $key => $value) {
            $environmentValues[$key] = $value;
        }

        try {
            $this->environment->set($environmentValues);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['env' => $e->getMessage()]);
        }

        $this->integrations->update(
            collect((array) ($validated['packages'] ?? []))->mapWithKeys(static fn ($value, $package): array => [(string) $package => (bool) $value])->all(),
            collect((array) ($validated['gateways'] ?? []))->mapWithKeys(static fn ($value, $id): array => [(int) $id => (bool) $value])->all(),
        );

        $warnings = $this->refreshRuntimeConfiguration();

        Log::info('payment-gateway.settings.updated', array_merge($context, [
            'env_keys' => array_keys($environmentValues),
        ]));

        return new SettingsSaveResult(array_keys($environmentValues), $warnings);
    }

    /**
     * Keeps the legacy unprefixed keys (KASHIER_MERCHANT_ID, ...) working:
     * a legacy value is first copied into the mode it belonged to, then the
     * legacy key is set to the active mode's value (empty when that mode has
     * none, so the other mode's credentials are never used by mistake).
     *
     * @param  array<string, string>  $pending  values about to be written
     * @return array<string, string>
     */
    private function legacyCredentialValues(array $pending): array
    {
        $writes = [];

        foreach ($this->modes() as $mode) {
            $previous = $this->currentMode($mode);
            $requested = strtolower((string) ($pending[$mode['env']] ?? ''));
            $next = in_array($requested, self::MODES, true) ? $requested : $previous;

            foreach ($mode['credentials'] as $credential) {
                $legacyKey = $credential['legacy'];
                if ($legacyKey === null) {
                    continue;
                }

                $legacyValue = (string) ($this->environment->get($legacyKey) ?? '');
                $previousKey = $credential['env'][$previous] ?? null;
                if ($previousKey !== null && $legacyValue !== '' && ! isset($pending[$previousKey]) && blank($this->environment->get($previousKey))) {
                    $writes[$previousKey] = $legacyValue;
                }

                $nextKey = $credential['env'][$next] ?? null;
                $active = $nextKey === null
                    ? ''
                    : (string) ($pending[$nextKey] ?? $writes[$nextKey] ?? $this->environment->get($nextKey) ?? '');

                if ($active !== $legacyValue) {
                    $writes[$legacyKey] = $active;
                }
            }
        }

        return $writes;
    }

    /**
     * New .env values only reach PHP-FPM after the config cache is cleared,
     * and queue workers only after they restart.
     *
     * @return list<string>
     */
    private function refreshRuntimeConfiguration(): array
    {
        $warnings = [];

        try {
            if (app()->configurationIsCached()) {
                // Rebuilding the cache inside this request would bake in the old
                // values (the environment is immutable once loaded), so clear it.
                Artisan::call('config:clear');
                $warnings[] = Translation::get('payment-gateway::admin.warnings.config_cache_cleared');
            }
        } catch (Throwable $e) {
            report($e);
            $warnings[] = Translation::get('payment-gateway::admin.warnings.config_cache_failed');
        }

        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            $warnings[] = Translation::get('payment-gateway::admin.warnings.queue_restart');
        }

        return $warnings;
    }

    /**
     * Translated field labels for validation messages; test/live credentials
     * carry their mode so both sets stay distinguishable.
     *
     * @param  array<string, array{package: string, gateway: string, group: string, name: string|null, field: array<string, mixed>, legacy: string|null}>  $definitions
     * @return array<string, string>
     */
    private function attributes(array $definitions): array
    {
        $attributes = [];
        foreach ($definitions as $key => $entry) {
            $label = Translation::get((string) ($entry['field']['label'] ?? $key));
            $attributes['env.'.$key] = in_array($entry['group'], self::MODES, true)
                ? Translation::get('payment-gateway::admin.attribute_with_mode', [
                    'label' => $label,
                    'mode' => Translation::get('payment-gateway::admin.modes.'.$entry['group']),
                ])
                : $label;
        }

        return $attributes;
    }

    private function permission(): ?string
    {
        $permission = config('payment-gateway.admin.permission');

        return is_string($permission) && trim($permission) !== '' ? $permission : null;
    }

    /** @return array<array-key, mixed> */
    private function packageConfigs(): array
    {
        return (array) config('payment-gateway.admin.packages', []);
    }

    /** @return array<string, array{id: int, enabled: bool, package_enabled: bool}> */
    private function gatewaySwitches(): array
    {
        $switches = [];
        foreach ($this->packages() as $package) {
            foreach ($package['gateways'] as $gateway) {
                $switches[$gateway['gateway']] = [
                    'id' => $gateway['id'],
                    'enabled' => $gateway['enabled'],
                    'package_enabled' => $package['enabled'],
                ];
            }
        }

        return $switches;
    }

    /**
     * Mode switches of gateways that have one (configs published before
     * per-mode credentials have none and behave as before).
     *
     * @return array<string, array{env: string, default: string, credentials: array<string, array{env: array<string, string>, legacy: string|null}>}>
     */
    private function modes(): array
    {
        $modes = [];
        foreach ($this->packageConfigs() as $packageConfig) {
            foreach ((array) ($packageConfig['gateways'] ?? []) as $gatewayKey => $gatewayConfig) {
                $mode = $gatewayConfig['mode'] ?? null;
                if (! is_array($mode) || ! is_string($mode['env'] ?? null) || $mode['env'] === '') {
                    continue;
                }

                $credentials = [];
                foreach ((array) ($gatewayConfig['credentials'] ?? []) as $name => $credential) {
                    $keys = [];
                    foreach (self::MODES as $modeName) {
                        $key = $credential['env'][$modeName] ?? null;
                        if (is_string($key) && $key !== '' && ! $this->isLocked($key)) {
                            $keys[$modeName] = $key;
                        }
                    }

                    $legacy = $credential['legacy_env'] ?? null;
                    $credentials[(string) $name] = [
                        'env' => $keys,
                        'legacy' => is_string($legacy) && $legacy !== '' && ! $this->isLocked($legacy) ? $legacy : null,
                    ];
                }

                $default = (string) ($mode['default'] ?? 'test');
                $modes[(string) $gatewayKey] = [
                    'env' => $mode['env'],
                    'default' => in_array($default, self::MODES, true) ? $default : 'test',
                    'credentials' => $credentials,
                ];
            }
        }

        return $modes;
    }

    /** @param array{env: string, default: string} $mode */
    private function currentMode(array $mode): string
    {
        $value = strtolower(trim((string) ($this->environment->get($mode['env']) ?? '')));

        return in_array($value, self::MODES, true) ? $value : $mode['default'];
    }

    /** @return array<string, array{package: string, gateway: string, group: string, name: string|null, field: array<string, mixed>, legacy: string|null}> */
    private function definitions(): array
    {
        $definitions = [];
        $add = function (string $key, string $package, string $gateway, string $group, ?string $name, array $field, ?string $legacy) use (&$definitions): void {
            if ($key === '' || $this->isLocked($key)) {
                return;
            }

            $definitions[$key] = [
                'package' => $package,
                'gateway' => $gateway,
                'group' => $group,
                'name' => $name,
                'field' => $field,
                'legacy' => $legacy,
            ];
        };

        foreach ($this->packageConfigs() as $packageKey => $packageConfig) {
            foreach ((array) ($packageConfig['gateways'] ?? []) as $gatewayKey => $gatewayConfig) {
                $packageKey = (string) $packageKey;
                $gatewayKey = (string) $gatewayKey;
                $mode = $gatewayConfig['mode'] ?? null;

                if (is_array($mode) && is_string($mode['env'] ?? null)) {
                    $options = array_intersect_key(
                        (array) ($mode['options'] ?? ['test' => 'payment-gateway::admin.modes.test', 'live' => 'payment-gateway::admin.modes.live']),
                        array_flip(self::MODES),
                    );
                    $add($mode['env'], $packageKey, $gatewayKey, self::GROUP_MODE, null, [
                        'label' => $mode['label'] ?? 'payment-gateway::admin.fields.environment',
                        'type' => 'select',
                        'options' => $options,
                    ], null);
                }

                foreach ((array) ($gatewayConfig['credentials'] ?? []) as $name => $credential) {
                    $credential = (array) $credential;
                    $legacy = $credential['legacy_env'] ?? null;
                    $field = array_diff_key($credential, ['env' => true, 'legacy_env' => true]);

                    foreach (self::MODES as $modeName) {
                        $key = $credential['env'][$modeName] ?? null;
                        if (is_string($key)) {
                            $add($key, $packageKey, $gatewayKey, $modeName, (string) $name, $field, is_string($legacy) && $legacy !== '' ? $legacy : null);
                        }
                    }
                }

                foreach (array_merge((array) ($gatewayConfig['env'] ?? []), (array) ($gatewayConfig['extra_env'] ?? [])) as $key => $field) {
                    $add((string) $key, $packageKey, $gatewayKey, self::GROUP_SETTINGS, null, (array) $field, null);
                }
            }
        }

        return $definitions;
    }

    private function isLocked(string $key): bool
    {
        return preg_match(self::LOCKED_KEY_PATTERN, $key) === 1;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    private function rulesFor(array $field): array
    {
        $noControl = 'not_regex:/[\x00-\x1F\x7F\']/';

        $textRules = ['nullable', 'string', 'max:255', $noControl];
        if (isset($field['pattern'])) {
            $textRules[] = 'regex:'.$field['pattern'];
        }

        return match ($field['type'] ?? 'text') {
            'select' => ['nullable', Rule::in(array_map('strval', array_keys((array) ($field['options'] ?? []))))],
            'boolean' => ['nullable', 'boolean'],
            'number' => ['nullable', 'integer', 'min:'.(int) ($field['min'] ?? 0), 'max:'.(int) ($field['max'] ?? 100000)],
            'url' => ['nullable', 'string', 'max:2048', 'url:https', $noControl],
            'secret' => ['nullable', 'string', 'max:1024', $noControl],
            default => $textRules,
        };
    }

    /**
     * Returns null when nothing should be written (blank input never erases a value).
     *
     * @param  array<string, mixed>  $field
     */
    private function normalize(array $field, mixed $value): ?string
    {
        $type = $field['type'] ?? 'text';

        if ($type === 'boolean') {
            return $value === null ? null : (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        // A form prefilled from values() posts the placeholder back: keep the stored secret.
        if ($type === 'secret' && $value === self::SECRET_PLACEHOLDER) {
            return null;
        }

        return $value;
    }

    private function routeUrl(mixed $definition): ?string
    {
        if (! is_array($definition)) {
            return null;
        }

        foreach ($this->routeCandidates($definition) as [$name, $parameters]) {
            if (Route::has($name)) {
                return route($name, $parameters);
            }
        }

        $envKey = $definition['env'] ?? null;
        $value = is_string($envKey) && $envKey !== '' ? $this->environment->get($envKey) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $definition
     * @return list<array{0: string, 1: array<array-key, mixed>}>
     */
    private function routeCandidates(array $definition): array
    {
        $candidates = [];

        foreach ((array) ($definition['candidates'] ?? []) as $candidate) {
            if (is_array($candidate) && is_string($candidate['route'] ?? null) && $candidate['route'] !== '') {
                $candidates[] = [$candidate['route'], (array) ($candidate['parameters'] ?? [])];
            }
        }

        if (is_string($definition['route'] ?? null) && $definition['route'] !== '') {
            $candidates[] = [$definition['route'], (array) ($definition['parameters'] ?? [])];
        }

        return $candidates;
    }

    /** @return array<string, string> URLs derived from routes, written to their env keys */
    private function generatedEnvironmentValues(): array
    {
        $values = [];

        foreach ($this->packageConfigs() as $packageConfig) {
            foreach ((array) ($packageConfig['gateways'] ?? []) as $gatewayConfig) {
                foreach (['webhook', 'return'] as $type) {
                    $definition = $gatewayConfig['routes'][$type] ?? null;
                    if (! is_array($definition) || ! is_string($definition['env'] ?? null) || $definition['env'] === '' || $this->isLocked($definition['env'])) {
                        continue;
                    }

                    foreach ($this->routeCandidates($definition) as [$name, $parameters]) {
                        if (Route::has($name)) {
                            $values[$definition['env']] = route($name, $parameters);
                            break;
                        }
                    }
                }
            }
        }

        return $values;
    }
}
