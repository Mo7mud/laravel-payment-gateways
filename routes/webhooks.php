<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mo7mud\LaravelPaymentGateway\Http\Controllers\ReturnController;
use Mo7mud\LaravelPaymentGateway\Http\Controllers\WebhookController;

Route::middleware((array) config('payment-gateway.routes.middleware', ['api']))
    ->prefix((string) config('payment-gateway.routes.prefix', 'payment-gateway'))
    ->group(function (): void {
        // Fawaterk: configure one dashboard URL per type, e.g. .../webhooks/fawaterk/paid_json,
        // .../fawaterk/failed, .../fawaterk/cancel, .../fawaterk/refund.
        Route::post('webhooks/{gateway}/{type?}', WebhookController::class)
            ->where(['gateway' => '[a-z0-9_-]+', 'type' => '[a-z_]+'])
            ->name('payment-gateway.webhook');

        Route::match(['get', 'post'], 'return/{gateway}', ReturnController::class)
            ->where('gateway', '[a-z0-9_-]+')
            ->name('payment-gateway.return');
    });
