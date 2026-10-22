<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Enums;

use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    public function test_core_payment_statuses_are_defined(): void
    {
        self::assertSame('pending', PaymentStatus::PENDING->value);
        self::assertSame('processing', PaymentStatus::PROCESSING->value);
        self::assertSame('succeeded', PaymentStatus::SUCCEEDED->value);
        self::assertSame('failed', PaymentStatus::FAILED->value);
        self::assertSame('cancelled', PaymentStatus::CANCELLED->value);
        self::assertSame('partially_refunded', PaymentStatus::PARTIALLY_REFUNDED->value);
        self::assertSame('refunded', PaymentStatus::REFUNDED->value);
    }

    public function test_refund_statuses_are_separate_from_payment_statuses(): void
    {
        self::assertSame('succeeded', RefundStatus::SUCCEEDED->value);
        self::assertNotSame(PaymentStatus::class, RefundStatus::class);
    }
}
