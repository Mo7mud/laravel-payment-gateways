<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnRequest;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;

/**
 * Browser redirect after checkout. Never changes payment state. A verified
 * redirect may expose its payment reference even when the local payment is
 * not resolved yet; unsigned return references are correlation-only. The
 * integrating application must perform any server-side status check and
 * fulfil orders from PaymentSucceeded.
 */
final class ReturnController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentService $payments,
        GatewayManager $gateways,
        string $gateway,
    ): RedirectResponse|JsonResponse {
        $gateway = strtolower($gateway);
        if (!$gateways->has($gateway)) {
            abort(404);
        }

        $query = $request->isMethod('post')
            ? array_replace($request->query->all(), $request->request->all())
            : $request->query->all();

        $reference = null;
        $status = null;
        $error = null;

        try {
            $result = $payments->handleReturn(new PaymentReturnRequest($query), $gateway);

            // The return handler has already applied the gateway's own trust
            // rules. A verified Kashier return may legitimately arrive before
            // a matching local payment is loaded, so do not erase its reference.
            // For unsigned gateways (currently Fawaterk), the reference is only
            // a correlation value; the application must still query the gateway
            // server-to-server before changing any business state.
            if ($result->signatureVerified || $result->localStatus !== null) {
                $reference = $result->paymentReference !== '' ? $result->paymentReference : null;
            }

            if ($result->localStatus !== null) {
                $status = $result->localStatus->value;
            } elseif ($reference !== null) {
                $error = 'payment_not_resolved';
            } else {
                $error = 'return_reference_missing';
            }
        } catch (WebhookVerificationException) {
            $error = 'invalid_return_signature';
        }

        $template = config('payment-gateway.return.redirect_url');
        if (is_string($template) && $template !== '') {
            $redirect = str_replace('{reference}', rawurlencode($reference ?? ''), $template);

            if (str_contains($redirect, '{error}')) {
                $redirect = str_replace('{error}', rawurlencode($error ?? ''), $redirect);
            }

            return redirect()->away($redirect);
        }

        return response()->json([
            'payment_reference' => $reference,
            'status' => $status,
            'error' => $error,
        ]);
    }
}
