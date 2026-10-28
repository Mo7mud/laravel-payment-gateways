<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\DTOs;

final readonly class ReconciliationResult
{
    /**
     * @param  array<mixed>  $failures
     */
    public function __construct(
        public int $examined,
        public int $updated,
        public int $skipped,
        public int $failed,
        public array $failures = [],
    ) {}
}
