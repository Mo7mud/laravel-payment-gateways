<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\Exceptions\GatewayApiException;
use Mo7mud\LaravelPaymentGateway\Exceptions\GatewayReferenceMismatchException;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentGatewayExceptionInterface;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentAmountMismatchException;
use Mo7mud\LaravelPaymentGateway\Exceptions\PaymentNotFoundException;
use Mo7mud\LaravelPaymentGateway\Exceptions\UnsupportedPaymentOperationException;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\Exceptions\FawaterkConfigurationException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierConfigurationException;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;

/**
 * Server-to-server notifications. Response codes drive gateway retries:
 *  - 401: signature invalid (never processed);
 *  - 200: processed, ignored, or failed permanently (stored as FAILED for review);
 *  - 503: the confirmation call to the gateway failed transiently (timeout, 429, 5xx); retry later;
 *  - 200 after a permanent confirmation failure (4xx): logged as critical, recovered by reconciliation;
 *  - 503: our own gateway configuration is missing or invalid; retry after it is fixed;
 *  - 200: any other package error that redelivery cannot fix (stored as FAILED);
 *  - 500: unexpected error (e.g. database); retry later;
 *  - duplicates: 200, or the per-gateway code in webhooks.duplicate_status (Kashier: 409).
 */
final class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentService $payments,
        GatewayManager $gateways,
        string $gateway,
        ?string $type = null,
    ): Response {
        $gateway = strtolower($gateway);
        if (!$gateways->has($gateway)) {
            abort(404);
        }

        $payload = $request->isJson() ? (array) $request->json()->all() : $request->request->all();

        try {
            $result = $payments->handleWebhook(
                new WebhookRequest(
                    $payload,
                    $request->headers->all(),
                    new DateTimeImmutable(),
                    $type,
                    (string) $request->getContent(),
                    $request->query->all(),
                ),
                $gateway,
            );
        } catch (WebhookVerificationException $e) {
            // Repeated rejections usually mean a wrong key or someone probing the endpoint.
            Log::channel(config('payment-gateway.logging.channel'))->warning('payment.webhook_rejected', [
                'gateway' => $gateway,
                'type' => $type,
                'ip' => $request->ip(),
                'reason' => $e->getMessage(),
            ]);

            return response('', 401);
        } catch (PaymentNotFoundException|PaymentAmountMismatchException|GatewayReferenceMismatchException|UnsupportedPaymentOperationException) {
            return response('', 200);
        } catch (GatewayApiException $e) {
            if ($e->isTransient()) {
                // Timeout, 429 or 5xx while confirming with the gateway: retry later.
                return response('', 503);
            }

            // A permanent API error (bad credentials, 4xx) will not heal by
            // retrying. The event is stored as failed and reconciliation
            // recovers the payment once the cause is fixed.
            Log::channel(config('payment-gateway.logging.channel'))->critical('payment.webhook_verification_rejected', [
                'gateway' => $gateway,
                'status' => $e->statusCode,
            ]);

            return response('', 200);
        } catch (KashierConfigurationException|FawaterkConfigurationException $e) {
            // Missing or wrong credentials on our side. Not the gateway's fault and
            // not permanent: answer 503 so the notification is delivered again
            // once the configuration is fixed, and alert loudly.
            Log::channel(config('payment-gateway.logging.channel'))->critical('payment.webhook_configuration_error', [
                'gateway' => $gateway,
                'type' => $type,
                'reason' => $e->getMessage(),
            ]);

            return response('', 503);
        } catch (PaymentGatewayExceptionInterface $e) {
            // Any other domain error (e.g. an impossible state transition) will not
            // heal by redelivery. The event is stored as failed for review and
            // reconciliation recovers the payment; a 500 would only cause retries.
            Log::channel(config('payment-gateway.logging.channel'))->error('payment.webhook_not_applied', [
                'gateway' => $gateway,
                'type' => $type,
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);

            return response('', 200);
        }

        if ($result->duplicate) {
            return response('', (int) config('payment-gateway.webhooks.duplicate_status.' . $gateway, 200));
        }

        return response('', 200);
    }
}
