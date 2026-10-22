<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Infrastructure;

use Closure;
use Mo7mud\LaravelPaymentGateway\Contracts\TransactionRunnerInterface;

final class InMemoryTransactionRunner implements TransactionRunnerInterface
{
    public function run(Closure $callback): mixed
    {
        return $callback();
    }
}
