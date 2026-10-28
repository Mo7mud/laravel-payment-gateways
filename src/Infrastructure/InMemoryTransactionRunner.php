<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Infrastructure;

use Closure;
use Mo7mud\LaravelPaymentGateway\Contracts\TransactionRunnerInterface;

final class InMemoryTransactionRunner implements TransactionRunnerInterface
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        return $callback();
    }
}
