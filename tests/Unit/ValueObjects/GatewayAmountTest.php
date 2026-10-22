<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\ValueObjects;

use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class GatewayAmountTest extends TestCase
{
    public function test_gateway_amounts_are_rounded_half_up_to_the_currency_scale(): void
    {
        $egp = Currency::egp();

        self::assertSame(1235, Money::fromGatewayAmount('12.345', $egp)->minorAmount);
        self::assertSame(1234, Money::fromGatewayAmount('12.344', $egp)->minorAmount);
        self::assertSame(1050, Money::fromGatewayAmount(10.5, $egp)->minorAmount);
        self::assertSame(100, Money::fromGatewayAmount(1, $egp)->minorAmount);
        self::assertSame(0, Money::fromGatewayAmount('0.004', $egp)->minorAmount);
    }

    public function test_iso_scales_and_gateway_aliases(): void
    {
        self::assertSame(3, Currency::fromIso('KWD')->fractionDigits);
        self::assertSame(0, Currency::fromIso('JPY')->fractionDigits);
        self::assertSame('SAR', Currency::fromGatewayCode('SR')->code);
        self::assertSame(12345, Money::fromGatewayAmount('12.345', Currency::fromIso('KWD'))->minorAmount);
    }

    public function test_equivalence_ignores_scale_differences_but_not_currency(): void
    {
        $twoDigits = Money::fromDecimal('10.50', Currency::fromCode('KWD', 2));
        $threeDigits = Money::fromDecimal('10.500', Currency::fromIso('KWD'));

        self::assertFalse($twoDigits->equals($threeDigits));
        self::assertTrue($twoDigits->isEquivalentTo($threeDigits));
        self::assertFalse(Money::fromDecimal('10.50', Currency::egp())->isEquivalentTo(Money::fromDecimal('10.50', Currency::fromIso('USD'))));
    }

    public function test_strict_parser_still_rejects_extra_digits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimal('1.005', Currency::egp());
    }
}
