<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;

final class CredentialModeConfigTest extends TestCase
{
    private const KEYS = ['KASHIER_ENVIRONMENT', 'KASHIER_MERCHANT_ID', 'KASHIER_TEST_MERCHANT_ID', 'KASHIER_LIVE_MERCHANT_ID'];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_the_active_mode_wins_and_the_legacy_key_is_the_fallback(): void
    {
        $this->environment([
            'KASHIER_ENVIRONMENT' => 'live',
            'KASHIER_MERCHANT_ID' => 'MID-LEGACY',
            'KASHIER_TEST_MERCHANT_ID' => 'MID-TEST',
            'KASHIER_LIVE_MERCHANT_ID' => 'MID-LIVE',
        ]);
        self::assertSame('MID-LIVE', $this->merchantId());

        $this->environment(['KASHIER_ENVIRONMENT' => 'test']);
        self::assertSame('MID-TEST', $this->merchantId());

        $this->environment(['KASHIER_TEST_MERCHANT_ID' => '']);
        self::assertSame('MID-LEGACY', $this->merchantId());
    }

    /** @param array<string, string> $values */
    private function environment(array $values): void
    {
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function merchantId(): string
    {
        $config = require dirname(__DIR__, 3).'/config/payment-gateway.php';

        return $config['kashier']['merchant_id'];
    }
}
