<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Contracts;

use Mo7mud\LaravelPaymentGateway\Contracts\PaymentCreatorInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentRefundInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentStatusProviderInterface;
use Mo7mud\LaravelPaymentGateway\Contracts\WebhookHandlerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CapabilityInterfacesTest extends TestCase
{
    public function test_capability_interfaces_are_separate_contracts(): void
    {
        foreach ([
            PaymentCreatorInterface::class,
            PaymentStatusProviderInterface::class,
            PaymentRefundInterface::class,
            WebhookHandlerInterface::class,
        ] as $interface) {
            self::assertTrue((new ReflectionClass($interface))->isInterface());
        }
    }
}
