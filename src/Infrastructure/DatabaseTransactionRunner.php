<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Infrastructure;

use Closure;
use Illuminate\Database\DatabaseManager;
use Mo7mud\LaravelPaymentGateway\Contracts\TransactionRunnerInterface;

final class DatabaseTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    public function run(Closure $callback): mixed
    {
        return $this->database->transaction($callback);
    }
}
