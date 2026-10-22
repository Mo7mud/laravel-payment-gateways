<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mo7mud\LaravelPaymentGateway\Support\PackageConfig;
use Mo7mud\LaravelPaymentGateway\Support\SchemaGuard;

return new class extends Migration
{
    public function up(): void
    {
        SchemaGuard::assertOwned('payments');
        $name = PackageConfig::table('payments');

        Schema::table($name, function (Blueprint $table) use ($name): void {
            if (!Schema::hasColumn($name, 'gateway_order_reference')) {
                $table->string('gateway_order_reference')->nullable()->after('gateway_transaction_id');
                $table->index(['gateway', 'gateway_order_reference'], $name . '_gateway_order_ref_index');
            }
            if (!Schema::hasColumn($name, 'attempt')) {
                $table->unsignedSmallInteger('attempt')->default(1)->after('status');
            }
            if (!Schema::hasColumn($name, 'refunded_amount_minor')) {
                $table->unsignedBigInteger('refunded_amount_minor')->default(0)->after('currency_scale');
            }
            if (!Schema::hasColumn($name, 'late_payment')) {
                $table->boolean('late_payment')->default(false)->after('refunded_amount_minor');
            }
            if (!Schema::hasColumn($name, 'last_error')) {
                $table->string('last_error', 500)->nullable()->after('gateway_status');
            }
            if (!Schema::hasColumn($name, 'next_reconcile_at')) {
                $table->timestamp('next_reconcile_at')->nullable()->after('refunded_at');
                $table->index(['status', 'next_reconcile_at'], $name . '_status_next_reconcile_index');
            }
            if (!Schema::hasColumn($name, 'reconcile_attempts')) {
                $table->unsignedInteger('reconcile_attempts')->default(0)->after('next_reconcile_at');
            }
            $table->index(['gateway', 'gateway_reference'], $name . '_gateway_gateway_ref_index');
            $table->index(['gateway', 'gateway_transaction_id'], $name . '_gateway_tx_index');
        });

        // Backfill: the first attempt used the payment reference as merchant order id,
        // and rc1 kept the refunded total in metadata.
        DB::table($name)->whereNull('gateway_order_reference')->update([
            'gateway_order_reference' => DB::raw('reference'),
        ]);

        DB::table($name)
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($name): void {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) $row->metadata, true);
                    $refunded = is_array($metadata) ? (int) ($metadata['refunded_amount_minor'] ?? 0) : 0;
                    if ($refunded > 0) {
                        DB::table($name)->where('id', $row->id)->update(['refunded_amount_minor' => $refunded]);
                    }
                }
            });
    }

    public function down(): void
    {
        $name = PackageConfig::table('payments');

        Schema::table($name, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name . '_gateway_order_ref_index');
            $table->dropIndex($name . '_status_next_reconcile_index');
            $table->dropIndex($name . '_gateway_gateway_ref_index');
            $table->dropIndex($name . '_gateway_tx_index');
            $table->dropColumn([
                'gateway_order_reference', 'attempt', 'refunded_amount_minor', 'late_payment',
                'last_error', 'next_reconcile_at', 'reconcile_attempts',
            ]);
        });
    }
};
