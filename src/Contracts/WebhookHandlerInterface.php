<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookResult;

interface WebhookHandlerInterface
{
    public function handleWebhook(WebhookRequest $request): WebhookResult;
}
