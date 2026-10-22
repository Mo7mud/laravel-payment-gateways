<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Support;

use InvalidArgumentException;
use Mo7mud\LaravelPaymentGateway\Support\DecimalNumber;
use Mo7mud\LaravelPaymentGateway\Support\Json;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class ExactDecimalTest extends TestCase
{
    public function test_decimals_are_written_as_exact_json_numbers_whatever_the_precision_setting(): void
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $json = Json::encode([
                'refundable_amount' => DecimalNumber::fromMoney(Money::fromDecimal('125.55', Currency::egp())),
                'cartItems' => [['name' => 'Seat "A"', 'price' => new DecimalNumber('0.10'), 'quantity' => 3]],
            ]);
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }

        self::assertSame('{"refundable_amount":125.55,"cartItems":[{"name":"Seat \"A\"","price":0.10,"quantity":3}]}', $json);
        self::assertSame(125.55, json_decode($json, true)['refundable_amount']);
    }

    public function test_only_plain_decimals_are_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DecimalNumber('1e3');
    }

    public function test_amounts_that_do_not_fit_are_rejected_instead_of_saturated(): void
    {
        foreach (['999999999999999999999999', '10000000000000000.00'] as $amount) {
            try {
                Money::fromDecimal($amount, Currency::egp());
                self::fail('Expected an exception for ' . $amount);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        Money::fromGatewayAmount(1.0e30, Currency::egp());
    }

    public function test_the_largest_supported_amount_round_trips(): void
    {
        $money = Money::fromMinorUnits(Money::MAX_MINOR, Currency::egp());

        self::assertSame('9999999999999999.99', $money->toDecimal());
        self::assertTrue(Money::fromDecimal('9999999999999999.99', Currency::egp())->isEquivalentTo($money));
    }
}
