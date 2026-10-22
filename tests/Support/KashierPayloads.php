<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Support;

use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;

final class KashierPayloads
{
    public const API_KEY = 'payment-api-key';

    /** Builds a webhook signed the way Kashier signs it (sorted keys, encoded values). */
    public static function webhook(
        string $event,
        string $status,
        string $transactionId,
        string $merchantOrderId,
        string $amount = '100',
        array $extra = [],
        string $key = self::API_KEY,
    ): WebhookRequest {
        $data = array_replace([
            'amount' => $amount,
            'currency' => 'EGP',
            'kashierOrderId' => 'K-ORDER-1',
            'merchantOrderId' => $merchantOrderId,
            'status' => $status,
            'transactionId' => $transactionId,
        ], $extra);

        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        $data['signatureKeys'] = $keys;

        $message = implode('&', array_map(
            static fn (string $name): string => $name . '=' . rawurlencode(is_bool($data[$name]) ? ($data[$name] ? 'true' : 'false') : (string) $data[$name]),
            $keys,
        ));

        return new WebhookRequest(
            ['event' => $event, 'data' => $data],
            ['x-kashier-signature' => hash_hmac('sha256', $message, $key)],
        );
    }
}
