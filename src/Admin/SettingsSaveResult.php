<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Admin;

/** Outcome of GatewaySettingsManager::save(). */
final class SettingsSaveResult
{
    /**
     * @param  list<string>  $environmentKeys  environment variables that were written
     * @param  list<string>  $warnings  follow-up actions for the operator (cache, queue workers)
     */
    public function __construct(
        public readonly array $environmentKeys,
        public readonly array $warnings,
    ) {}

    public function firstWarning(): ?string
    {
        return $this->warnings[0] ?? null;
    }
}
