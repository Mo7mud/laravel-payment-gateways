<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;

final class SandboxConfigContractTest extends TestCase
{
    public function test_kashier_example_uses_the_package_webhook_environment_variable_name(): void
    {
        $env = file_get_contents(__DIR__ . '/../../Fixtures/kashier.env.example');

        self::assertIsString($env);
        self::assertStringContainsString('KASHIER_SERVER_WEBHOOK_URL=', $env);
        self::assertStringNotContainsString('KASHIER_SERVER_WEBHOOK=', $env);
    }
}
