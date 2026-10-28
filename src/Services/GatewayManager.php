<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Services;

use Closure;
use InvalidArgumentException;
use Mo7mud\LaravelPaymentGateway\Contracts\PaymentGatewayInterface;
use Mo7mud\LaravelPaymentGateway\Enums\GatewayName;
use Throwable;

/**
 * Resolves gateways lazily, so a gateway that is registered but not configured
 * cannot break the others.
 *
 * Accepted definitions:
 *  - ['kashier' => KashierGateway::class, ...] keyed class names (preferred, fully lazy)
 *  - [KashierGateway::class, ...] a list of class names (resolved on first lookup)
 *  - [$gatewayInstance, ...] ready instances (tests)
 */
final class GatewayManager
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $resolved = [];

    /** @var array<string, class-string|Closure> keyed definitions */
    private array $keyed = [];

    /** @var list<class-string|Closure> unkeyed definitions */
    private array $unkeyed = [];

    /** @var array<string, string> definition => last resolution error */
    private array $errors = [];

    /** @var Closure(string): PaymentGatewayInterface */
    private Closure $resolver;

    /**
     * @param  iterable<int|string, PaymentGatewayInterface|class-string|Closure>  $gateways
     * @param  (Closure(string): PaymentGatewayInterface)|null  $resolver
     */
    public function __construct(iterable $gateways, ?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (string $class): PaymentGatewayInterface => new $class;

        foreach ($gateways as $key => $gateway) {
            if ($gateway instanceof PaymentGatewayInterface) {
                $this->resolved[self::keyOf($gateway->name())] = $gateway;

                continue;
            }

            if (is_string($key)) {
                $this->keyed[strtolower($key)] = $gateway;
            } else {
                $this->unkeyed[] = $gateway;
            }
        }
    }

    public static function keyOf(GatewayName|string $name): string
    {
        return GatewayName::keyOf($name);
    }

    public function gateway(GatewayName|string $name): PaymentGatewayInterface
    {
        $key = self::keyOf($name);

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if (isset($this->keyed[$key])) {
            $gateway = $this->build($this->keyed[$key]);
            unset($this->keyed[$key]);

            return $this->resolved[$key] = $gateway;
        }

        foreach ($this->unkeyed as $index => $definition) {
            try {
                $gateway = $this->build($definition);
            } catch (Throwable $e) {
                $this->errors[is_string($definition) ? $definition : 'closure#'.$index] = $e->getMessage();

                continue;
            }

            unset($this->unkeyed[$index]);
            $this->resolved[self::keyOf($gateway->name())] = $gateway;

            if (isset($this->resolved[$key])) {
                return $this->resolved[$key];
            }
        }

        $message = sprintf('Payment gateway [%s] is not registered.', $key);
        if ($this->errors !== []) {
            $message .= ' Some gateways failed to load: '.implode('; ', array_map(
                static fn (string $definition, string $error): string => $definition.': '.$error,
                array_keys($this->errors),
                $this->errors,
            ));
        }

        throw new InvalidArgumentException($message);
    }

    public function has(GatewayName|string $name): bool
    {
        $key = self::keyOf($name);

        return isset($this->resolved[$key]) || isset($this->keyed[$key]);
    }

    private function build(string|Closure $definition): PaymentGatewayInterface
    {
        $gateway = $definition instanceof Closure ? $definition() : ($this->resolver)($definition);

        if (! $gateway instanceof PaymentGatewayInterface) {
            throw new InvalidArgumentException('Gateway definition does not resolve to a PaymentGatewayInterface.');
        }

        return $gateway;
    }
}
