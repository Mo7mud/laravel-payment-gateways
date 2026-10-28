<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mo7mud\LaravelPaymentGateway\Admin\GatewaySettingsManager;
use Mo7mud\LaravelPaymentGateway\Admin\PaymentIntegrationService;
use Mo7mud\LaravelPaymentGateway\Support\Translation;

/** Built-in Blade page; all logic lives in GatewaySettingsManager. */
final class PaymentGatewaySettingsController extends Controller
{
    /** Placeholder passed to the view instead of real secret values. */
    public const SECRET_PLACEHOLDER = GatewaySettingsManager::SECRET_PLACEHOLDER;

    public function __construct(
        private readonly GatewaySettingsManager $settings,
        private readonly PaymentIntegrationService $integrations,
    ) {}

    public function index(): View
    {
        $this->settings->authorize();

        $gateways = $this->settings->gateways();
        $values = $this->settings->values();
        foreach ($gateways as $gatewayKey => $gateway) {
            $values[$gatewayKey.'.webhook_url'] = $gateway['webhook_url'];
            $values[$gatewayKey.'.return_url'] = $gateway['return_url'];
        }

        /** @var view-string $view */
        $view = 'payment-gateway::admin.settings';

        return view($view, [
            'packages' => $this->integrations->packages(),
            'gatewayConfigs' => (array) config('payment-gateway.admin.packages', []),
            'values' => $values,
            'gateways' => $gateways,
            'type_menu' => 'settings',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->settings->authorize();

        $result = $this->settings->save($request->all(), [
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        $redirect = redirect()->route('payment-gateway.admin.settings')
            ->with('success', Translation::get('payment-gateway::admin.messages.saved'));

        $warning = $result->firstWarning();

        return $warning !== null ? $redirect->with('warning', $warning) : $redirect;
    }
}
