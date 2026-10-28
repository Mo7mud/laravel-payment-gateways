<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Services;

use Mo7mud\LaravelPaymentGateway\Enums\PaymentStatus;
use Mo7mud\LaravelPaymentGateway\Enums\TransitionDecision;
use Mo7mud\LaravelPaymentGateway\Exceptions\InvalidPaymentStateTransitionException;
use Mo7mud\LaravelPaymentGateway\Services\PaymentStateManager;
use PHPUnit\Framework\TestCase;

final class PaymentStateManagerTest extends TestCase
{
    public function test_valid_transition_is_allowed(): void
    {
        $manager = new PaymentStateManager;

        self::assertTrue($manager->canTransition(PaymentStatus::PENDING, PaymentStatus::SUCCEEDED));
        self::assertSame(
            PaymentStatus::PROCESSING,
            $manager->transition(PaymentStatus::PENDING, PaymentStatus::PROCESSING)
        );
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $manager = new PaymentStateManager;

        $this->expectException(InvalidPaymentStateTransitionException::class);
        $manager->transition(PaymentStatus::REFUNDED, PaymentStatus::SUCCEEDED);
    }

    public function test_capture_after_closing_is_a_late_payment_not_an_error(): void
    {
        $manager = new PaymentStateManager;

        foreach ([PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::EXPIRED] as $closed) {
            self::assertSame(TransitionDecision::LATE_PAYMENT, $manager->decide($closed, PaymentStatus::SUCCEEDED));
        }
    }

    public function test_out_of_order_updates_are_stale(): void
    {
        $manager = new PaymentStateManager;

        self::assertSame(TransitionDecision::STALE, $manager->decide(PaymentStatus::SUCCEEDED, PaymentStatus::PENDING));
        self::assertSame(TransitionDecision::STALE, $manager->decide(PaymentStatus::SUCCEEDED, PaymentStatus::FAILED));
        self::assertSame(TransitionDecision::STALE, $manager->decide(PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::SUCCEEDED));
        self::assertSame(TransitionDecision::NOOP, $manager->decide(PaymentStatus::PENDING, PaymentStatus::PENDING));
        self::assertSame(TransitionDecision::APPLY, $manager->decide(PaymentStatus::PENDING, PaymentStatus::SUCCEEDED));
    }

    public function test_same_state_is_idempotent(): void
    {
        $manager = new PaymentStateManager;

        self::assertTrue($manager->canTransition(PaymentStatus::SUCCEEDED, PaymentStatus::SUCCEEDED));
        self::assertSame(
            PaymentStatus::SUCCEEDED,
            $manager->transition(PaymentStatus::SUCCEEDED, PaymentStatus::SUCCEEDED)
        );
    }

    public function test_refund_lifecycle_is_allowed(): void
    {
        $manager = new PaymentStateManager;

        self::assertTrue($manager->canTransition(PaymentStatus::SUCCEEDED, PaymentStatus::REFUNDING));
        self::assertTrue($manager->canTransition(PaymentStatus::REFUNDING, PaymentStatus::PARTIALLY_REFUNDED));
        self::assertTrue($manager->canTransition(PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::REFUNDED));
    }
}
