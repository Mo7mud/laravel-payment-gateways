<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\Exceptions\FawaterkApiException;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\Exceptions\FawaterkConfigurationException;

final class FawaterkTokenManager
{
    /**
     * @param  callable(string, array): array  $tokenRequester
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $environment,
        private readonly string $apiBaseUrl,
        private readonly Closure $tokenRequester,
        private readonly int $clockSkew = 60,
    ) {}

    public function accessToken(): string
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new FawaterkConfigurationException('Fawaterk OAuth client_id and client_secret are required.');
        }

        $cached = $this->cache->get($this->cacheKey());
        if (is_array($cached) && $this->isAccessTokenValid($cached)) {
            return (string) $cached['access_token'];
        }

        // One worker refreshes; the others wait and reuse its token instead of
        // stampeding the OAuth endpoint (and invalidating each other's refresh tokens).
        $store = method_exists($this->cache, 'getStore') ? $this->cache->getStore() : null;
        if ($store instanceof LockProvider) {
            try {
                return $store->lock($this->cacheKey().':lock', 15)->block(10, fn (): string => $this->refreshAccessToken());
            } catch (LockTimeoutException) {
                // Fall through and fetch without the lock.
            }
        }

        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): string
    {
        $cached = $this->cache->get($this->cacheKey());
        if (is_array($cached) && $this->isAccessTokenValid($cached)) {
            return (string) $cached['access_token'];
        }

        if (is_array($cached) && ! empty($cached['refresh_token'])) {
            try {
                $token = ($this->tokenRequester)('refresh_token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => (string) $cached['refresh_token'],
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);

                return $this->storeToken($token, (string) $cached['refresh_token']);
            } catch (FawaterkApiException $e) {
                if ($e->statusCode !== 400 && $e->statusCode !== 401) {
                    throw $e;
                }
            }
        }

        $token = ($this->tokenRequester)('client_credentials', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        return $this->storeToken($token, null);
    }

    public function forgetAccessToken(): void
    {
        $cached = $this->cache->get($this->cacheKey());
        if (! is_array($cached)) {
            return;
        }

        $cached['expires_at'] = 0;
        $this->cache->put($this->cacheKey(), $cached, max(1, $this->clockSkew + 1));
    }

    private function isAccessTokenValid(array $cached): bool
    {
        return isset($cached['access_token'], $cached['expires_at'])
            && is_string($cached['access_token'])
            && $cached['access_token'] !== ''
            && (int) $cached['expires_at'] > (time() + $this->clockSkew);
    }

    private function storeToken(array $token, ?string $previousRefreshToken): string
    {
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new FawaterkApiException('Fawaterk OAuth response did not include access_token.');
        }

        $expiresIn = max(60, (int) ($token['expires_in'] ?? 3600));
        $refreshToken = isset($token['refresh_token']) && (string) $token['refresh_token'] !== ''
            ? (string) $token['refresh_token']
            : $previousRefreshToken;

        $cacheTtl = max(1, $expiresIn);
        $this->cache->put($this->cacheKey(), [
            'access_token' => $accessToken,
            'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
            'expires_at' => time() + $expiresIn,
            'refresh_token' => $refreshToken,
        ], $cacheTtl);

        return $accessToken;
    }

    private function cacheKey(): string
    {
        return 'mo7mud.payment-gateway.fawaterk.oauth.'.hash('sha256', implode('|', [
            $this->environment,
            $this->apiBaseUrl,
            $this->clientId,
        ]));
    }
}
