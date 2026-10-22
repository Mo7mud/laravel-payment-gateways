<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mo7mud\LaravelPaymentGateway\Admin\EnvironmentManager;
use Mo7mud\LaravelPaymentGateway\Admin\PaymentIntegrationService;
use Throwable;

final class PaymentGatewaySettingsController extends Controller
{
    /** Placeholder passed to the view instead of real secret values. */
    public const SECRET_PLACEHOLDER = '__configured__';

    public function __construct(
        private readonly PaymentIntegrationService $integrations,
        private readonly EnvironmentManager $environment,
    ) {
    }

    public function index(): View
    {
        $this->authorizeAccess();

        $gatewayConfigs = (array) config('payment-gateway.admin.packages', []);
        $values = [];

        foreach ($this->fieldDefinitions() as $envKey => $field) {
            $value = $this->environment->get($envKey);
            // Secrets never reach the browser; the view only needs to know they are set.
            $values[$envKey] = ($field['type'] ?? 'text') === 'secret'
                ? (filled($value) ? self::SECRET_PLACEHOLDER : null)
                : $value;
        }

        foreach ($gatewayConfigs as $packageConfig) {
            foreach ((array) ($packageConfig['gateways'] ?? []) as $gatewayKey => $gatewayConfig) {
                $values[$gatewayKey . '.webhook_url'] = $this->routeUrl($gatewayConfig['routes']['webhook'] ?? null);
                $values[$gatewayKey . '.return_url'] = $this->routeUrl($gatewayConfig['routes']['return'] ?? null);
            }
        }

        return view('payment-gateway::admin.settings', [
            'packages' => $this->integrations->packages(),
            'gatewayConfigs' => $gatewayConfigs,
            'values' => $values,
            'type_menu' => 'settings',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeAccess();

        $fields = $this->fieldDefinitions();
        $rules = [
            'packages' => ['nullable', 'array'],
            'packages.*' => ['nullable', 'boolean'],
            'gateways' => ['nullable', 'array'],
            'gateways.*' => ['nullable', 'boolean'],
            'env' => ['nullable', 'array'],
        ];
        foreach ($fields as $key => $field) {
            $rules['env.' . $key] = $this->rulesFor($key, $field);
        }

        $validated = $request->validate($rules);

        $environmentValues = [];
        foreach ((array) ($validated['env'] ?? []) as $key => $value) {
            if (!isset($fields[$key])) {
                continue;
            }

            $normalized = $this->normalize($fields[$key], $value);
            if ($normalized !== null) {
                $environmentValues[$key] = $normalized;
            }
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

        $warning = $this->refreshRuntimeConfiguration();

        Log::info('payment-gateway.settings.updated', [
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $request->ip(),
            'env_keys' => array_keys($environmentValues),
        ]);

        $redirect = redirect()->route('payment-gateway.admin.settings')
            ->with('success', 'Payment gateway settings saved.');

        return $warning !== null ? $redirect->with('warning', $warning) : $redirect;
    }

    /** Fails closed: without a configured Gate ability nobody can open the page. */
    private function authorizeAccess(): void
    {
        $permission = config('payment-gateway.admin.permission');

        if (!is_string($permission) || trim($permission) === '') {
            abort(403, 'Payment gateway settings are locked. Set PAYMENT_GATEWAY_ADMIN_PERMISSION to a Gate ability.');
        }

        Gate::authorize($permission);
    }

    /**
     * New .env values only reach PHP-FPM after the config cache is cleared,
     * and queue workers only after they restart.
     */
    private function refreshRuntimeConfiguration(): ?string
    {
        $warning = null;

        try {
            if (app()->configurationIsCached()) {
                // Rebuilding the cache inside this request would bake in the old
                // values (the environment is immutable once loaded), so clear it.
                Artisan::call('config:clear');
                $warning = 'Configuration cache was cleared. Run "php artisan config:cache" to rebuild it.';
            }
        } catch (Throwable $e) {
            report($e);
            $warning = 'Settings were saved, but the configuration cache could not be cleared. Run "php artisan config:clear".';
        }

        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            $warning ??= 'Settings were saved. Restart queue workers so they use the new values.';
        }

        return $warning;
    }

    /** @return array<string, array<string, mixed>> */
    private function fieldDefinitions(): array
    {
        $fields = [];
        foreach ((array) config('payment-gateway.admin.packages', []) as $packageConfig) {
            foreach ((array) ($packageConfig['gateways'] ?? []) as $gatewayConfig) {
                foreach (array_merge((array) ($gatewayConfig['env'] ?? []), (array) ($gatewayConfig['extra_env'] ?? [])) as $key => $field) {
                    $fields[(string) $key] = (array) $field;
                }
            }
        }

        return $fields;
    }

    private function rulesFor(string $key, array $field): array
    {
        $noControl = 'not_regex:/[\x00-\x1F\x7F\']/';

        return match ($field['type'] ?? 'text') {
            'select' => ['nullable', Rule::in(array_map('strval', array_keys((array) ($field['options'] ?? []))))],
            'boolean' => ['nullable', 'boolean'],
            'number' => ['nullable', 'integer', 'min:' . (int) ($field['min'] ?? 0), 'max:' . (int) ($field['max'] ?? 100000)],
            'url' => ['nullable', 'string', 'max:2048', 'url:https', $noControl],
            'secret' => ['nullable', 'string', 'max:1024', $noControl],
            default => array_values(array_filter([
                'nullable',
                'string',
                'max:255',
                $noControl,
                isset($field['pattern']) ? 'regex:' . $field['pattern'] : null,
            ])),
        };
    }

    /** Returns null when nothing should be written (blank input never erases a value). */
    private function normalize(array $field, mixed $value): ?string
    {
        if (($field['type'] ?? 'text') === 'boolean') {
            return $value === null ? null : (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function routeUrl(mixed $definition): ?string
    {
        if (!is_array($definition)) {
            return null;
        }

        foreach ($this->routeCandidates($definition) as [$name, $parameters]) {
            if (Route::has($name)) {
                return route($name, $parameters);
            }
        }

        $envKey = $definition['env'] ?? null;

        return is_string($envKey) && $envKey !== '' ? $this->environment->get($envKey) : null;
    }

    /** @return list<array{0: string, 1: array}> */
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

        foreach ((array) config('payment-gateway.admin.packages', []) as $packageConfig) {
            foreach ((array) ($packageConfig['gateways'] ?? []) as $gatewayConfig) {
                foreach (['webhook', 'return'] as $type) {
                    $definition = $gatewayConfig['routes'][$type] ?? null;
                    if (!is_array($definition) || !is_string($definition['env'] ?? null) || $definition['env'] === '') {
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
