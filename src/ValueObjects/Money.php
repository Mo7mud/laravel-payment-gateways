<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\ValueObjects;

use InvalidArgumentException;

final readonly class Money
{
    /**
     * Largest accepted amount in minor units: 18 digits always fit in a 64-bit
     * integer, so parsing and plus() can never overflow or saturate silently.
     */
    public const MAX_MINOR = 999_999_999_999_999_999;

    public function __construct(
        public int $minorAmount,
        public Currency $currency,
    ) {
        if ($minorAmount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        if ($minorAmount > self::MAX_MINOR) {
            throw new InvalidArgumentException('Money amount exceeds the supported maximum.');
        }
    }

    public static function fromMinorUnits(int $minorAmount, Currency $currency): self
    {
        return new self($minorAmount, $currency);
    }

    /**
     * Backward-compatible alias for callers using the shorter name.
     */
    public static function fromMinor(int $minorAmount, Currency $currency): self
    {
        return self::fromMinorUnits($minorAmount, $currency);
    }

    /**
     * Strict parser for amounts the application controls: rejects more
     * fractional digits than the currency supports.
     */
    public static function fromDecimal(string $amount, Currency $currency): self
    {
        $amount = trim($amount);
        $scale = $currency->fractionDigits;

        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('Money amount must be a non-negative decimal string.');
        }

        $whole = $matches[1];
        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException('Money amount has more fractional digits than the currency supports.');
        }

        $fraction = str_pad($fraction, $scale, '0');

        return new self(self::digitsToInt($whole.$fraction), $currency);
    }

    /**
     * Tolerant parser for amounts reported by gateways (JSON numbers, converted
     * totals). Extra fractional digits are rounded half-up to the currency scale.
     */
    public static function fromGatewayAmount(int|float|string $amount, Currency $currency): self
    {
        $scale = $currency->fractionDigits;

        if (is_int($amount)) {
            $amount = (string) $amount;
        } elseif (is_float($amount)) {
            if (! is_finite($amount) || $amount < 0 || $amount >= 1e17) {
                throw new InvalidArgumentException('Gateway amount must be a finite, non-negative number within range.');
            }
            $amount = number_format($amount, $scale, '.', '');
        }

        $amount = trim($amount);
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('Gateway amount must be a non-negative decimal.');
        }

        $fraction = $matches[2] ?? '';
        if (strlen($fraction) <= $scale) {
            return self::fromDecimal($amount, $currency);
        }

        $kept = substr($fraction, 0, $scale);
        $minor = self::digitsToInt($matches[1].$kept);
        if ((int) $fraction[$scale] >= 5) {
            if ($minor >= self::MAX_MINOR) {
                throw new InvalidArgumentException('Money amount exceeds the supported maximum.');
            }
            $minor++;
        }

        return new self($minor, $currency);
    }

    public function toDecimal(): string
    {
        $scale = $this->currency->fractionDigits;

        if ($scale === 0) {
            return (string) $this->minorAmount;
        }

        $raw = str_pad((string) $this->minorAmount, $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($raw, 0, -$scale);
        $fraction = substr($raw, -$scale);

        return $whole.'.'.$fraction;
    }

    public function equals(self $other): bool
    {
        return $this->minorAmount === $other->minorAmount
            && $this->currency->equals($other->currency);
    }

    /**
     * Same currency code and same value, even when the two sides were built
     * with different fraction digits (e.g. 10.5 at scale 1 and 10.50 at scale 2).
     */
    public function isEquivalentTo(self $other): bool
    {
        if ($this->currency->code !== $other->currency->code) {
            return false;
        }

        // Compared as normalised decimal strings: no multiplication, so no overflow.
        return self::normaliseDecimal($this->toDecimal()) === self::normaliseDecimal($other->toDecimal());
    }

    /** Converts to minor units of $currency (same code), rounding half-up if needed. */
    public function toCurrencyScale(Currency $currency): self
    {
        if ($currency->code !== $this->currency->code) {
            throw new InvalidArgumentException('Cannot rescale money into a different currency.');
        }

        return self::fromGatewayAmount($this->toDecimal(), $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        $other = $other->toCurrencyScale($this->currency);
        if ($other->minorAmount > self::MAX_MINOR - $this->minorAmount) {
            throw new InvalidArgumentException('Money amount exceeds the supported maximum.');
        }

        return new self($this->minorAmount + $other->minorAmount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            max(0, $this->minorAmount - $other->toCurrencyScale($this->currency)->minorAmount),
            $this->currency,
        );
    }

    /** Converts a digit string to int, rejecting anything that would not fit exactly. */
    private static function digitsToInt(string $digits): int
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }

        if (strlen($digits) > 18) {
            throw new InvalidArgumentException('Money amount exceeds the supported maximum.');
        }

        return (int) $digits;
    }

    private static function normaliseDecimal(string $decimal): string
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        return ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency->code !== $this->currency->code) {
            throw new InvalidArgumentException('Money currencies do not match.');
        }
    }
}
