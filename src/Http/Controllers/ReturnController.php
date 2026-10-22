<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnRequest;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use Mo7mud\LaravelPaymentGateway\Repositories\PaymentRepositoryInterface;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Support\ReturnRedirect;

/**
 * Browser redirect after checkout. The query string never changes payment
 * state; an open payment may be checked once with the gateway API
 * (return.sync_status). A verified redirect may expose its payment reference
 * even when the local payment is not resolved yet; unsigned return references
 * are correlation-only. Orders are fulfilled from PaymentSucceeded, never here.
 */
final class ReturnController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentService $payments,
        PaymentRepositoryInterface $repository,
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
        $paymentRedirect = null;

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
                $paymentRedirect = $reference !== null
                    ? ($repository->findByReference($reference)?->metadata[ReturnRedirect::METADATA_KEY] ?? null)
                    : null;
            } elseif ($reference !== null) {
                $error = 'payment_not_resolved';
            } else {
                $error = 'return_reference_missing';
            }
        } catch (WebhookVerificationException) {
            $error = 'invalid_return_signature';
        }

        $values = ['reference' => $reference ?? '', 'status' => $status ?? '', 'gateway' => $gateway, 'error' => $error ?? ''];

        // Re-checked here: allowed_hosts may have changed since the payment was created.
        if (is_string($paymentRedirect) && $paymentRedirect !== '') {
            $redirect = ReturnRedirect::render($paymentRedirect, $values);
            if (ReturnRedirect::isAllowed($redirect, (array) config('payment-gateway.return.allowed_hosts', []))) {
                return redirect()->away($redirect);
            }
        }

        $template = config('payment-gateway.return.redirect_url');
        if (is_string($template) && $template !== '') {
            return redirect()->away(ReturnRedirect::render($template, $values));
        }

        return response()->json([
            'payment_reference' => $reference,
            'status' => $status,
            'error' => $error,
        ]);
    }
}
