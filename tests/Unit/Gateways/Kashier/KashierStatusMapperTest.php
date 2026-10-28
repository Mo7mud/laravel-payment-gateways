<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Kashier;

use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\Exceptions\KashierUnexpectedStatusException;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use PHPUnit\Framework\TestCase;

final class KashierStatusMapperTest extends TestCase
{
    public function test_known_kashier_statuses_are_normalized(): void
    {
        $mapper = new KashierStatusMapper;

        self::assertSame(PaymentStatus::PENDING, $mapper->map('CREATED'));
        self::assertSame(PaymentStatus::PROCESSING, $mapper->map('AUTHORIZED'));
        self::assertSame(PaymentStatus::SUCCEEDED, $mapper->map('CAPTURED'));
        self::assertSame(PaymentStatus::FAILED, $mapper->map('REFUSED'));
        self::assertSame(PaymentStatus::CANCELLED, $mapper->map('CANCELLED'));
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $mapper->map('PARTIALLY_REFUNDED'));
        self::assertSame(PaymentStatus::REFUNDED, $mapper->map('REFUNDED'));
    }

    public function test_unknown_status_is_not_silently_treated_as_failure(): void
    {
        $this->expectException(KashierUnexpectedStatusException::class);

        (new KashierStatusMapper)->map('SOME_NEW_STATUS');
    }
}
