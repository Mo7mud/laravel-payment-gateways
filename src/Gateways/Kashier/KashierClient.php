<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Gateways\Kashier;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierApiException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierConfigurationException;
use Throwable;

final class KashierClient implements KashierClientInterface, KashierPaginatedOrdersInterface, KashierSessionCancellationInterface
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $secretKey,
        private readonly string $paymentApiKey,
        private readonly string $apiBaseUrl,
        private readonly string $fepBaseUrl,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
        private readonly int $readRetries = 2,
    ) {}

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function createPaymentSession(array $payload): array
    {
        return $this->request('post', rtrim($this->apiBaseUrl, '/').'/v3/payment/sessions', [
            'Authorization' => $this->secretKey,
            'api-key' => $this->paymentApiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $payload);
    }

    /**
     * @return array<mixed>
     */
    public function getPaymentSession(string $sessionId): array
    {
        return $this->request('get', rtrim($this->apiBaseUrl, '/').'/v3/payment/sessions/'.rawurlencode($sessionId).'/payment', [
            'Authorization' => $this->secretKey,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * @return array<mixed>
     */
    public function abandonPaymentSession(string $sessionId): array
    {
        // Not retried automatically (only reads are).
        return $this->request('patch', rtrim($this->apiBaseUrl, '/').'/v3/payment/sessions/'.rawurlencode($sessionId), [
            'Authorization' => $this->secretKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], []);
    }

    /**
     * @return array<mixed>
     */
    public function getOrderStatus(string $merchantOrderId, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array
    {
        return $this->searchOrders($merchantOrderId, null, null, $status, $startDate, $endDate);
    }

    /**
     * @return array<mixed>
     */
    public function getOrdersPage(string $merchantOrderId, int $page, int $limit = 20): array
    {
        // Only "page" is sent: the page size stays Kashier's documented default.
        return $this->searchOrders($merchantOrderId, $page > 1 ? $page : null, null);
    }

    /**
     * @return array<mixed>
     */
    public function refund(string $orderId, ?string $amount, ?string $reason): array
    {
        $transaction = [];
        if ($amount !== null) {
            $transaction['amount'] = $amount;
        }

        $body = ['apiOperation' => 'REFUND', 'transaction' => $transaction];
        if ($reason !== null && $reason !== '') {
            $body['reason'] = $reason;
        }

        // Not retried automatically: a refund is not idempotent at Kashier.
        return $this->request('put', rtrim($this->fepBaseUrl, '/').'/v3/orders/'.rawurlencode($orderId), [
            'Authorization' => $this->secretKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $body);
    }

    /**
     * @return array<mixed>
     */
    private function searchOrders(
        string $merchantOrderId,
        ?int $page,
        ?int $limit,
        ?string $status = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        // "search" is a partial match; callers must filter on merchantOrderId.
        $query = ['search' => $merchantOrderId];
        if ($page !== null) {
            $query['page'] = $page;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
        }
        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }
        if ($startDate !== null && $startDate !== '') {
            $query['startDate'] = $startDate;
        }
        if ($endDate !== null && $endDate !== '') {
            $query['endDate'] = $endDate;
        }

        $url = rtrim($this->apiBaseUrl, '/').'/v3/payment/orders?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $this->request('get', $url, [
            'Authorization' => $this->secretKey,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * @param  array<mixed>|null  $body
     * @param  array<mixed>  $headers
     * @return array<mixed>
     */
    private function request(string $method, string $url, array $headers, ?array $body = null): array
    {
        if ($this->secretKey === '' || $this->paymentApiKey === '') {
            throw new KashierConfigurationException('Kashier Secret Key and Payment API Key are required.');
        }

        try {
            $request = $this->http
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->withHeaders($headers);

            if ($method === 'get' && $this->readRetries > 0) {
                // Reads only: connection errors, 429 and 5xx, with growing back-off.
                $backoff = array_slice([300, 1000, 3000], 0, $this->readRetries);
                $request = $request->retry(
                    $backoff,
                    0,
                    static fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)),
                    false,
                );
            }

            $response = match ($method) {
                'get' => $request->get($url),
                'post' => $request->post($url, $body ?? []),
                'put' => $request->put($url, $body ?? []),
                // Documented body is {}; an empty PHP array would be encoded as [].
                'patch' => $body === null || $body === []
                    ? $request->withBody('{}', 'application/json')->patch($url)
                    : $request->patch($url, $body),
                default => throw new KashierApiException('Unsupported Kashier HTTP method ['.$method.'].'),
            };
        } catch (KashierApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new KashierApiException('Kashier API request failed: '.$e::class, 0);
        }

        $json = $response->json();
        if (! is_array($json)) {
            $json = ['raw' => mb_substr((string) $response->body(), 0, 2000)];
        }

        if ($response->failed()) {
            $message = $json['messages']['en']
                ?? $json['message']
                ?? $json['error']['cause']
                ?? 'Kashier API request failed.';

            throw new KashierApiException(mb_substr((string) $message, 0, 300), $response->status(), $json);
        }

        return $json;
    }
}
