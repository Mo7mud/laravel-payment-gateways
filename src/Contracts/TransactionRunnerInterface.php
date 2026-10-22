<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Contracts;

use Closure;

interface TransactionRunnerInterface
{
    /** @template T */
    public function run(Closure $callback): mixed;
}
