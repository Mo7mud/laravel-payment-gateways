<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Console;

use Illuminate\Console\Command;
use Mo7mud\LaravelPaymentGateway\Admin\PaymentIntegrationService;

final class SyncIntegrationsCommand extends Command
{
    protected $signature = 'payments:sync-integrations';

    protected $description = 'Create or update the admin on/off switches from config/payment-gateway.php.';

    public function handle(PaymentIntegrationService $integrations): int
    {
        $integrations->syncDefinitions();
        $this->info('Payment integrations synchronised. Enabled gateways: '.implode(', ', $integrations->activeGatewayKeys()));

        return self::SUCCESS;
    }
}
