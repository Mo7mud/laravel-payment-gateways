<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_decimal_amount_is_converted_to_minor_units_without_float_math(): void
    {
        $money = Money::fromDecimal('100.50', Currency::egp());

        self::assertSame(10050, $money->minorAmount);
        self::assertSame('100.50', $money->toDecimal());
    }

    public function test_zero_is_supported(): void
    {
        $money = Money::fromDecimal('0.00', Currency::egp());

        self::assertSame(0, $money->minorAmount);
        self::assertSame('0.00', $money->toDecimal());
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('-1.00', Currency::egp());
    }

    public function test_excess_fraction_digits_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('10.001', Currency::egp());
    }

    public function test_currency_code_is_normalized_to_uppercase(): void
    {
        $currency = Currency::fromCode('egp', 2);

        self::assertSame('EGP', $currency->code);
    }
}
