<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Integration;

use Mo7mud\LaravelPaymentGateway\DTOs\PaymentReturnRequest;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Gateways\Kashier\KashierStatusMapper;
use Mo7mud\LaravelPaymentGateway\Exceptions\WebhookVerificationException;
use PHPUnit\Framework\TestCase;

final class KashierActualRedirectTest extends TestCase
{
    public function test_captured_successful_redirect_signature_is_valid(): void
    {
        $paymentApiKey = getenv('KASHIER_PAYMENT_API_KEY') ?: '';
        if ($paymentApiKey === '') {
            $this->markTestSkipped('KASHIER_PAYMENT_API_KEY is not configured.');
        }

        $query = [];
        parse_str(
            'paymentStatus=SUCCESS&cardDataToken=&maskedCard=&merchantOrderId=TEST-20260814083906&orderId=b1428090-f0c6-4875-80a2-01419024b100&cardBrand=&orderReference=TEST-ORD-193506693&transactionId=TX-4885276905&amount=1&currency=EGP&mode=test&signature=77deac148566e29fd5a45ae900a50843d68047ec176f4967c844b3651af91439',
            $query,
        );

        (new KashierSignatureVerifier($paymentApiKey))->verifyRedirect($query);
        $this->addToAssertionCount(1);
    }
}
