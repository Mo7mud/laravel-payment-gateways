<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Enums;

enum RefundStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
