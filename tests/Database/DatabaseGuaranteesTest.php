<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\RefundStatus;
use Mo7mud\LaravelPaymentGateway\Enums\WebhookEventStatus;
use Mo7mud\LaravelPaymentGateway\Models\Payment;
use Mo7mud\LaravelPaymentGateway\Models\PaymentRefund;
use Mo7mud\LaravelPaymentGateway\Repositories\EloquentPaymentRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\EloquentRefundRepository;
use Mo7mud\LaravelPaymentGateway\Repositories\EloquentWebhookEventRepository;
use Mo7mud\LaravelPaymentGateway\Support\Uuid;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Currency;
use Mo7mud\LaravelPaymentGateway\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

/**
 * Guarantees the payment logic relies on, checked against the real database.
 * Runs on SQLite by default; CI runs it on MySQL and PostgreSQL too.
 */
final class DatabaseGuaranteesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        Capsule::connection()->rollBack();
        parent::tearDown();
    }

    public function test_a_merchant_order_id_cannot_belong_to_two_payments(): void
    {
        $repository = new EloquentPaymentRepository();
        $repository->save($this->payment('ORD-1', 'ORD-1-A2-abc123'));

        $this->expectException(QueryException::class);
        $repository->save($this->payment('ORD-1-A2-abc123', 'ORD-1-A2-abc123'));
    }

    public function test_a_gateway_transaction_id_cannot_belong_to_two_payments(): void
    {
        $repository = new EloquentPaymentRepository();
        $first = $this->payment('ORD-T1', 'ORD-T1');
        $first->gateway_transaction_id = 'TX-1';
        $repository->save($first);

        $second = $this->payment('ORD-T2', 'ORD-T2');
        $second->gateway_transaction_id = 'TX-1';

        $this->expectException(QueryException::class);
        $repository->save($second);
    }

    public function test_duplicate_webhook_deliveries_produce_one_event(): void
    {
        $events = new EloquentWebhookEventRepository();

        self::assertTrue($events->storeIfAbsent('kashier', 'evt-1', 'pay', 'ORD-1', str_repeat('a', 64), [], WebhookEventStatus::RECEIVED));
        self::assertFalse($events->storeIfAbsent('kashier', 'evt-1', 'pay', 'ORD-1', str_repeat('a', 64), [], WebhookEventStatus::RECEIVED));

        $events->markStatus('kashier', 'evt-1', WebhookEventStatus::PROCESSED, 'applied');
        $events->markStatus('kashier', 'evt-1', WebhookEventStatus::FAILED, 'late failure from a concurrent delivery');

        self::assertSame(WebhookEventStatus::PROCESSED, $events->find('kashier', 'evt-1')?->status);
        self::assertSame(2, $events->find('kashier', 'evt-1')?->attempts);
    }

    public function test_an_idempotency_key_is_unique_per_payment(): void
    {
        $refunds = new EloquentRefundRepository();
        $refunds->save($this->refund('ORD-R', 'key-1'));

        $this->expectException(QueryException::class);
        $refunds->save($this->refund('ORD-R', 'key-1'));
    }

    public function test_pending_refund_lookup_runs_on_this_database(): void
    {
        $refunds = new EloquentRefundRepository();
        $refunds->save($this->refund('ORD-P1', null));
        $refunds->save($this->refund('ORD-P2', null));
        $refunds->save($this->refund('ORD-P1', null));

        $references = $refunds->paymentsWithPendingRefunds(new \DateTimeImmutable('+1 minute'));

        self::assertSame(['ORD-P1', 'ORD-P2'], $references);
        self::assertSame(2000, $refunds->sumAmount('ORD-P1', RefundStatus::PENDING));
    }

    public function test_row_locks_block_a_second_connection(): void
    {
        $driver = Capsule::connection()->getDriverName();
        if ($driver === 'sqlite') {
            self::markTestSkipped('Row locks need MySQL, MariaDB or PostgreSQL (set DB_TEST_DRIVER).');
        }

        // Committed outside the test transaction so the other connection sees it.
        Capsule::connection()->rollBack();
        $repository = new EloquentPaymentRepository();
        $repository->save($this->payment('ORD-LOCK', 'ORD-LOCK'));

        $first = Capsule::connection();
        $second = Capsule::connection('concurrent');
        $driver === 'pgsql'
            ? $second->statement("SET lock_timeout = '1s'")
            : $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $first->beginTransaction();
        $repository->findByReferenceForUpdate('ORD-LOCK');

        $blocked = false;
        try {
            $second->transaction(static function () use ($second): void {
                $second->table(\Mo7mud\LaravelPaymentGateway\Support\PackageConfig::table('payments'))->where('reference', 'ORD-LOCK')->lockForUpdate()->first();
            });
        } catch (QueryException) {
            $blocked = true;
        } finally {
            $first->rollBack();
            $first->table(\Mo7mud\LaravelPaymentGateway\Support\PackageConfig::table('payments'))->where('reference', 'ORD-LOCK')->delete();
            $first->beginTransaction();
        }

        self::assertTrue($blocked, 'A second connection must wait for the payment row lock.');
    }

    private function payment(string $reference, string $merchantReference): Payment
    {
        $payment = new Payment();
        $payment->reference = $reference;
        $payment->gateway = 'kashier';
        $payment->status = PaymentStatus::PENDING;
        $payment->setAmountMoney(Money::fromDecimal('10.00', Currency::egp()));
        $payment->gateway_order_reference = $merchantReference;
        $payment->metadata = [];

        return $payment;
    }

    private function refund(string $reference, ?string $key): PaymentRefund
    {
        $refund = new PaymentRefund();
        $refund->uuid = Uuid::v4();
        $refund->payment_reference = $reference;
        $refund->gateway = 'kashier';
        $refund->status = RefundStatus::PENDING;
        $refund->type = 'refund';
        $refund->source = 'api';
        $refund->amount_minor = 1000;
        $refund->currency = 'EGP';
        $refund->currency_scale = 2;
        $refund->idempotency_key = $key;

        return $refund;
    }
}
