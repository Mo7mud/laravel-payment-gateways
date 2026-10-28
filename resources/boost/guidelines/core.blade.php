## mo7mud/laravel-payment-gateway

One API (`Mo7mud\LaravelPaymentGateway\Services\PaymentService`) for Egyptian payment gateways (`kashier`, `fawaterk`). The package owns payment state; the application only reacts to events. Full guide: `docs/usage.md` in the package.

### Rules
- Never change payment state yourself, never trust the return page query string, and never fulfil an order from the return page. Fulfil only in a `PaymentSucceeded` listener.
- Money is always `ValueObjects\Money` in minor units. Never use floats.
- Do not call `createPayment()` inside your own `DB::transaction()` (it makes an HTTP call and delays events).
- `paymentReference` must be unique per order (use a ULID). The same reference with the same amount returns the same result.
- `checkoutUrl` can be `null` (Fawaterk reference payments such as Fawry): show `metadata['fawaterk_reference_number']` instead.

### Create a payment
@verbatim
<code-snippet name="Create a payment" lang="php">
use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\CustomerData;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;

$result = app(PaymentService::class)->createPayment('kashier', new CreatePaymentRequest(
    paymentReference: $order->payment_reference,
    amount: Money::fromDecimal('150.00', Currency::egp()),
    customer: new CustomerData(id: (string) $user->id, name: $user->name, email: $user->email, phone: $user->phone),
    returnUrl: route('orders.payment-return', $order),
));

return $result->checkoutUrl !== null ? redirect()->away($result->checkoutUrl) : back();
</code-snippet>
@endverbatim

### Events and the listener contract
Events (`Mo7mud\LaravelPaymentGateway\Events\*`) are dispatched after commit and carry scalars only: `PaymentSucceeded` (fulfil here; `latePayment` means it was paid after being closed), `PaymentClosed` (failed/cancelled/expired), `PaymentAttemptFailed`, `PaymentRefunded`, `LatePaymentReceived`, `PaymentStatusChanged`.

A `PaymentSucceeded` listener must be idempotent and must call `markFulfilled()` in the same transaction that fulfils the order. Unconfirmed paid payments get `PaymentSucceeded` again from `payments:redispatch-unfulfilled`.

@verbatim
<code-snippet name="PaymentSucceeded listener" lang="php">
use Illuminate\Support\Facades\DB;
use Mo7mud\LaravelPaymentGateway\Events\PaymentSucceeded;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;

public function handle(PaymentSucceeded $event, PaymentService $payments): void
{
    DB::transaction(function () use ($event, $payments): void {
        $order = Order::where('payment_reference', $event->paymentReference)->lockForUpdate()->firstOrFail();
        if (! $order->is_paid) {
            $order->markPaid();
        }
        $payments->markFulfilled($event->paymentReference);
    });
}
</code-snippet>
@endverbatim

### Refund and cancel
@verbatim
<code-snippet name="Refund and cancel" lang="php">
use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;

$payments->refund(new RefundPaymentRequest(
    paymentReference: $order->payment_reference,
    amount: Money::fromDecimal('50.00', Currency::egp()), // null refunds the remaining amount
    reason: 'Order cancelled',
    idempotencyKey: 'refund-order-'.$order->id,
));

$status = $payments->cancelPayment($order->payment_reference); // returns PaymentStatus; isPaid() means it was paid first
</code-snippet>
@endverbatim

- Fawaterk refunds stay pending until the refund webhook arrives; listen to `PaymentRefunded` instead of trusting the return value.

### Building a settings page in any admin panel
Use `Mo7mud\LaravelPaymentGateway\Admin\GatewaySettingsManager`; do not read or write `.env` directly. Labels, options and display names come translated. Secrets are never returned: a configured secret is `GatewaySettingsManager::SECRET_PLACEHOLDER`, and posting it back (or a blank value) keeps the stored secret.

@verbatim
<code-snippet name="Settings page data and save" lang="php">
use Illuminate\Validation\ValidationException;
use Mo7mud\LaravelPaymentGateway\Admin\GatewaySettingsManager;

$settings = app(GatewaySettingsManager::class);
$settings->authorize(); // 403 unless config('payment-gateway.admin.permission') names a Gate ability the user has

// Per gateway: display_name, enabled, id, mode (current, options), credentials['test'|'live'], settings, webhook_url, return_url.
// Each field: key (env name), label, type (text|secret|url|number|boolean|select), options, value.
$gateways = $settings->gateways();

try {
    $result = $settings->save([
        'env' => ['KASHIER_ENVIRONMENT' => 'live', 'KASHIER_LIVE_MERCHANT_ID' => 'MID-123'],
        'gateways' => [$gateways['kashier']['id'] => true],
    ], ['user_id' => auth()->id()]);
    $warning = $result->firstWarning(); // e.g. config cache cleared: show it to the admin
} catch (ValidationException $e) {
    // errors are keyed env.<KEY>
}
</code-snippet>
@endverbatim
