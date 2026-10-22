<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Gateways\Fawaterk;

use Mo7mud\LaravelPaymentGateway\DTOs\CreatePaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\CustomerData;
use Mo7mud\LaravelPaymentGateway\DTOs\RefundPaymentRequest;
use Mo7mud\LaravelPaymentGateway\DTOs\WebhookRequest;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkGateway;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkMapper;
use Mo7mud\LaravelPaymentGateway\Gateways\Fawaterk\FawaterkSignatureVerifier;
use Mo7mud\LaravelPaymentGateway\Infrastructure\InMemoryTransactionRunner;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\InMemoryWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Services\GatewayManager;
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use Mo7mud\LaravelPaymentGateway\Tests\Support\FakeFawaterkClient;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

/**
 * Fawaterk refund notifications carry no refund id and do not sign status or
 * approval time, so refunds are confirmed from the refund list API.
 */
final class FawaterkRefundConfirmationTest extends TestCase
{
    private const VENDOR_KEY = 'vendor-key';

    private FakeFawaterkClient $client;
    private InMemoryPaymentRepository $payments;
    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeFawaterkClient();
        $this->payments = new InMemoryPaymentRepository();
        $this->service = new PaymentService(
            new GatewayManager([new FawaterkGateway($this->client, new FawaterkMapper(), new FawaterkSignatureVerifier(self::VENDOR_KEY))]),
            $this->payments,
            new PaymentStateManager(),
            new InMemoryWebhookEventRepository(),
            new InMemoryTransactionRunner(),
            new InMemoryRefundRepository(),
        );

        $this->service->createPayment('fawaterk', new CreatePaymentRequest(
            'PAY-FR',
            Money::fromDecimal('100.00', Currency::egp()),
            new CustomerData(name: 'Ahmed Ali'),
        ));

        $this->client->transaction = [
            'paid' => 1, 'status_text' => 'paid', 'total' => '100.00', 'currency' => 'EGP',
            'intent_key' => 'intent-1', 'transaction_id' => 555,
            'pay_load' => json_encode(['payment_reference' => 'PAY-FR'], JSON_THROW_ON_ERROR),
        ];
        $this->service->handleWebhook(new WebhookRequest([
            'transaction_id' => 555, 'transaction_key' => 'intent-1', 'payment_method' => 'Card', 'status' => 'paid',
            'transactionHashKey' => hash_hmac('sha256', 'TransactionId=555&TransactionKey=intent-1&PaymentMethod=Card', self::VENDOR_KEY),
        ], [], null, 'paid_json'), 'fawaterk');
    }

    public function test_a_refund_is_recorded_only_once_the_refund_list_approves_it(): void
    {
        self::assertSame(PaymentStatus::SUCCEEDED, $this->payment()->status);

        $result = $this->service->refund(new RefundPaymentRequest('PAY-FR', Money::fromDecimal('40.00', Currency::egp()), 'Other'));
        self::assertSame(RefundStatus::PENDING, $result->status);

        // Notification before Fawaterk's list shows the approval: nothing is booked.
        $this->client->refundItems = [$this->item(9, '40.00', 'pending')];
        $early = $this->service->handleWebhook($this->refundWebhook('40.00', '2026-10-01 10:00:00'), 'fawaterk');
        self::assertFalse($early->duplicate);
        self::assertSame(0, (int) $this->payment()->refunded_amount_minor);

        // The same notification again once approved: booked.
        $this->client->refundItems = [$this->item(9, '40.00', 'approved')];
        $this->service->handleWebhook($this->refundWebhook('40.00', '2026-10-01 10:00:00'), 'fawaterk');
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $this->payment()->status);
        self::assertSame(4000, (int) $this->payment()->refunded_amount_minor);
    }

    public function test_a_replayed_notification_with_an_altered_unsigned_field_does_not_double_count(): void
    {
        $this->service->refund(new RefundPaymentRequest('PAY-FR', Money::fromDecimal('40.00', Currency::egp()), 'Other'));
        $this->client->refundItems = [$this->item(9, '40.00', 'approved')];

        $this->service->handleWebhook($this->refundWebhook('40.00', '2026-10-01 10:00:00'), 'fawaterk');
        // Valid signature (it only covers transactionId, amount, currency), new approvedAt => new event id.
        $this->service->handleWebhook($this->refundWebhook('40.00', '2026-10-01 10:05:00'), 'fawaterk');

        self::assertSame(4000, (int) $this->payment()->refunded_amount_minor);
    }

    public function test_a_refund_made_in_the_dashboard_is_discovered_and_invoice_refunds_are_ignored(): void
    {
        $this->client->refundItems = [
            $this->item(11, '30.00', 'approved'),
            // Same numeric id, different table: an invoice refund that is not ours.
            ['id' => 12, 'refundable_type' => 'Invoice', 'refundable_id' => 555, 'refundable_amount' => 70, 'status' => 'approved', 'created_at' => '2026-10-01T09:00:00Z'],
        ];

        $this->service->handleWebhook($this->refundWebhook('30.00', '2026-10-01 11:00:00'), 'fawaterk');

        self::assertSame(3000, (int) $this->payment()->refunded_amount_minor);
    }

    public function test_a_rejected_refund_releases_the_reserved_amount(): void
    {
        $this->service->refund(new RefundPaymentRequest('PAY-FR', Money::fromDecimal('100.00', Currency::egp()), 'Other'));

        $this->client->refundItems = [$this->item(9, '100.00', 'rejected')];
        $this->service->reconcilePayment(new \Mo7mud\LaravelPaymentGateway\DTOs\PaymentStatusRequest('PAY-FR'));

        self::assertSame(PaymentStatus::SUCCEEDED, $this->payment()->status);
        $retry = $this->service->refund(new RefundPaymentRequest('PAY-FR', Money::fromDecimal('100.00', Currency::egp()), 'Other'));
        self::assertSame(RefundStatus::PENDING, $retry->status);
        self::assertSame(2, $this->client->refundCalls);
    }

    private function payment(): \Mo7mud\LaravelPaymentGateway\Models\Payment
    {
        return $this->payments->findByReference('PAY-FR') ?? throw new \RuntimeException('missing payment');
    }

    private function item(int $id, string $amount, string $status): array
    {
        return [
            'id' => $id, 'refundable_type' => 'Transaction', 'refundable_id' => 555,
            'refundable_amount' => (float) $amount, 'status' => $status, 'created_at' => '2026-10-01T10:00:00Z',
        ];
    }

    private function refundWebhook(string $amount, string $approvedAt): WebhookRequest
    {
        return new WebhookRequest([
            'transactionId' => 555,
            'amount' => $amount,
            'currency' => 'EGP',
            'status' => 1,
            'approvedAt' => $approvedAt,
            'hashKey' => hash_hmac('sha256', 'transactionId=555&amount=' . $amount . '&currency=EGP', self::VENDOR_KEY),
        ], [], null, 'refund');
    }
}
