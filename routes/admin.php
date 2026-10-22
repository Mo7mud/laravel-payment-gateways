<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mo7mud\LaravelPaymentGateway\Http\Controllers\Admin\PaymentGatewaySettingsController;

// Loaded only when payment-gateway.admin.enabled is true (see PaymentServiceProvider).
Route::middleware((array) config('payment-gateway.admin.middleware', ['web', 'auth']))
    ->prefix((string) config('payment-gateway.admin.route_prefix', 'payment-gateway/settings'))
    ->group(function (): void {
        Route::get('/', [PaymentGatewaySettingsController::class, 'index'])
            ->name('payment-gateway.admin.settings');
        Route::post('/', [PaymentGatewaySettingsController::class, 'update'])
            ->middleware('throttle:20,1')
            ->name('payment-gateway.admin.settings.update');
    });
