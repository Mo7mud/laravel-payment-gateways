<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk;

use Illuminate\Http\Client\Factory;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\Exceptions\FawaterkApiException;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\Exceptions\FawaterkConfigurationException;
use Mo7mud\LaravelPaymentGateway\Support\Json;

final class FawaterkClient implements FawaterkClientInterface, FawaterkRefundPagesInterface
{
    public function __construct(
        private readonly Factory $http,
        private readonly FawaterkTokenManager $tokens,
        private readonly string $apiBaseUrl,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
    ) {}

    /**
     * @return array<mixed>
     */
    public function getPaymentMethods(): array
    {
        return $this->request('get', '/api/v3/getTrPaymentmethods')['data'] ?? [];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function createTransaction(array $payload): array
    {
        return $this->request('post', '/api/v3/createTransaction', $payload);
    }

    /**
     * @return array<mixed>
     */
    public function getTransactionData(string $intentKey): array
    {
        return $this->request('post', '/api/v3/getTransactionData', ['intent_key' => $intentKey]);
    }

    /**
     * @return array<mixed>
     */
    public function getTransactionsData(string $startDate, string $endDate, int $perPage = 15, ?string $payloadFilter = null, int $page = 1): array
    {
        return $this->request('get', '/api/v3/getTransactionsData', null, array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'per_page' => $perPage,
            'pay_load' => $payloadFilter,
            'page' => $page,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array<mixed>
     */
    public function getRefundTypes(?string $lang = null): array
    {
        return $this->request('post', '/api/v3/refund/refundTypes', $lang !== null ? ['lang' => $lang] : [])['data'] ?? [];
    }

    /**
     * @return array<mixed>
     */
    public function getRefundReasons(?string $lang = null): array
    {
        return $this->request('post', '/api/v3/refund/refundReasons', $lang !== null ? ['lang' => $lang] : [])['data'] ?? [];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function createRefund(array $payload): array
    {
        return $this->request('post', '/api/v3/refund/create', $payload);
    }

    /**
     * @return array<mixed>
     */
    public function deleteRefund(int $refundId): array
    {
        return $this->request('post', '/api/v3/refund/delete', ['refund_id' => $refundId]);
    }

    /**
     * @return array<mixed>
     */
    public function listRefunds(): array
    {
        return $this->request('post', '/api/v3/refund/index');
    }

    /**
     * @return array<mixed>
     */
    public function listRefundsPage(int $page): array
    {
        // Laravel paginators read "page" from the query string.
        return $this->request('post', '/api/v3/refund/index', [], ['page' => max(1, $page)]);
    }

    /**
     * @return array<mixed>
     */
    public function getRefundDetails(int $refundId): array
    {
        return $this->request('get', '/api/v3/refund/details/'.rawurlencode((string) $refundId));
    }

    /**
     * @param  array<mixed>|null  $body
     * @param  array<mixed>  $query
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        if ($this->apiBaseUrl === '') {
            throw new FawaterkConfigurationException('Fawaterk API base URL is required.');
        }

        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                $token = $this->tokens->accessToken();
                $pending = $this->http
                    ->connectTimeout($this->connectTimeout)
                    ->timeout($this->timeout)
                    ->withToken($token)
                    ->acceptJson();

                $response = match (strtolower($method)) {
                    'get' => $pending->get($this->url($path), $query),
                    // DecimalNumber values are written as exact JSON numbers.
                    'post' => Json::containsDecimals($body ?? [])
                        ? $pending->withBody(Json::encode($body ?? []), 'application/json')->post($this->url($path, $query))
                        : $pending->post($this->url($path, $query), $body ?? []),
                    default => throw new FawaterkApiException('Unsupported Fawaterk HTTP method ['.$method.'].'),
                };
            } catch (FawaterkApiException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new FawaterkApiException('Fawaterk API request failed: '.$e::class);
            }

            if ($response->status() === 401 && $attempts === 1) {
                $this->tokens->forgetAccessToken();

                continue;
            }

            $json = $response->json();
            if (! is_array($json)) {
                $json = ['raw' => mb_substr((string) $response->body(), 0, 2000)];
            }

            if ($response->failed()) {
                $message = $json['message']
                    ?? $json['error_description']
                    ?? $json['error']
                    ?? 'Fawaterk API request failed.';

                if (is_array($message)) {
                    $message = json_encode(
                        $message,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ) ?: 'Fawaterk API validation failed.';
                }

                throw new FawaterkApiException(mb_substr((string) $message, 0, 300), $response->status(), $json);
            }

            return $json;
        }
    }

    /**
     * @param  array<mixed>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = rtrim($this->apiBaseUrl, '/').'/'.ltrim($path, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }
}
