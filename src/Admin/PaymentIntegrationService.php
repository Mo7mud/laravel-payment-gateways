<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Admin;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Mo7mud\LaravelPaymentGateway\Contracts\GatewayAvailabilityInterface;
use Mo7mud\LaravelPaymentGateway\Models\PaymentIntegrationGateway;
use Mo7mud\LaravelPaymentGateway\Models\PaymentIntegrationPackage;

/**
 * Admin on/off switches for packages and gateways. Reads are cached and never
 * write; definitions are synchronised from the admin page and from
 * `php artisan payments:sync-integrations`.
 */
final class PaymentIntegrationService implements GatewayAvailabilityInterface
{
    private const CACHE_KEY = 'mo7mud.payment-gateway.active-gateways';

    public function __construct(
        private readonly ?CacheRepository $cache = null,
        private readonly int $cacheSeconds = 60,
    ) {
    }

    public function syncDefinitions(): void
    {
        foreach ((array) config('payment-gateway.admin.packages', []) as $packageKey => $package) {
            $packageModel = PaymentIntegrationPackage::updateOrCreate(
                ['package' => $packageKey],
                ['display_name' => $package['display_name'] ?? $packageKey],
            );

            foreach ((array) ($package['gateways'] ?? []) as $gatewayKey => $gateway) {
                PaymentIntegrationGateway::updateOrCreate(
                    ['package_id' => $packageModel->id, 'gateway' => $gatewayKey],
                    [
                        'display_name' => $gateway['display_name'] ?? ucfirst((string) $gatewayKey),
                        'sort_order' => (int) ($gateway['sort_order'] ?? 0),
                    ],
                );
            }
        }

        $this->flushCache();
    }

    public function packages(): Collection
    {
        $this->syncDefinitions();

        return PaymentIntegrationPackage::query()
            ->with(['gateways' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->orderBy('display_name')
            ->get();
    }

    /** @return list<string> */
    public function activeGatewayKeys(): array
    {
        $load = static fn (): array => PaymentIntegrationGateway::query()
            ->where('enabled', true)
            ->whereHas('package', fn ($query) => $query->where('enabled', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('gateway')
            ->map(static fn ($value): string => (string) $value)
            ->unique()
            ->values()
            ->all();

        return $this->cache !== null
            ? $this->cache->remember(self::CACHE_KEY, $this->cacheSeconds, $load)
            : $load();
    }

    public function isGatewayEnabled(string $gateway): bool
    {
        return in_array($gateway, $this->activeGatewayKeys(), true);
    }

    public function isEnabled(string $gateway): bool
    {
        return $this->isGatewayEnabled($gateway);
    }

    /**
     * @param array<string, bool> $packageStates keyed by package name
     * @param array<int, bool> $gatewayStates keyed by gateway row id
     */
    public function update(array $packageStates, array $gatewayStates): void
    {
        foreach ($packageStates as $package => $enabled) {
            PaymentIntegrationPackage::query()->where('package', $package)->update(['enabled' => (bool) $enabled]);
        }

        foreach ($gatewayStates as $id => $enabled) {
            PaymentIntegrationGateway::query()->whereKey((int) $id)->update(['enabled' => (bool) $enabled]);
        }

        $this->flushCache();
    }

    public function flushCache(): void
    {
        $this->cache?->forget(self::CACHE_KEY);
    }
}
