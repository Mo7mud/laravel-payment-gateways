<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Support;

use InvalidArgumentException;
use JsonSerializable;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use Stringable;

/**
 * An exact decimal that must be sent as a JSON *number* (APIs whose schema
 * says "number"). Json::encode() writes the digits verbatim, so the value
 * never passes through a PHP float and is unaffected by serialize_precision.
 */
final readonly class DecimalNumber implements JsonSerializable, Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^\d{1,18}(?:\.\d{1,6})?$/', $value) !== 1) {
            throw new InvalidArgumentException('DecimalNumber expects a plain non-negative decimal string.');
        }

        $this->value = $value;
    }

    public static function fromMoney(Money $money): self
    {
        return new self($money->toDecimal());
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /** Fallback for encoders other than Json::encode(). */
    public function jsonSerialize(): float
    {
        return (float) $this->value;
    }
}
