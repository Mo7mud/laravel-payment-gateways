<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\ValueObjects;

use InvalidArgumentException;

final readonly class Currency
{
    /** ISO 4217 minor-unit exponents that differ from the default of 2. */
    private const FRACTION_DIGITS = [
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'ISK' => 0, 'UGX' => 0, 'XAF' => 0, 'XOF' => 0,
    ];

    /** Non-ISO codes some gateways emit. Fawaterk stores SAR as "SR". */
    private const ALIASES = [
        'SR' => 'SAR',
        'LE' => 'EGP',
    ];

    public string $code;
    public int $fractionDigits;

    public function __construct(string $code, int $fractionDigits = 2)
    {
        $code = strtoupper(trim($code));

        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new InvalidArgumentException('Currency code must be a 3-letter ISO-style code.');
        }

        if ($fractionDigits < 0 || $fractionDigits > 4) {
            throw new InvalidArgumentException('Currency fraction digits must be between 0 and 4.');
        }

        $this->code = $code;
        $this->fractionDigits = $fractionDigits;
    }

    public static function fromCode(string $code, int $fractionDigits = 2): self
    {
        return new self($code, $fractionDigits);
    }

    /** Uses the ISO 4217 exponent for the code (2 when unknown). */
    public static function fromIso(string $code): self
    {
        $code = strtoupper(trim($code));

        return new self($code, self::FRACTION_DIGITS[$code] ?? 2);
    }

    /** Normalises gateway-specific aliases (e.g. "SR") before applying ISO rules. */
    public static function fromGatewayCode(string $code): self
    {
        $code = strtoupper(trim($code));

        return self::fromIso(self::ALIASES[$code] ?? $code);
    }

    public static function egp(): self
    {
        return new self('EGP', 2);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code
            && $this->fractionDigits === $other->fractionDigits;
    }
}
