<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mo7mud\LaravelPaymentGateway\Support\PackageConfig;
use Mo7mud\LaravelPaymentGateway\Support\SchemaGuard;

return new class extends Migration
{
    public function up(): void
    {
        $name = PackageConfig::table('refunds');
        if (SchemaGuard::existsAndOwned('refunds')) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('payment_reference');
            $table->string('gateway', 50);
            $table->string('status', 30);
            $table->string('type', 20)->default('refund');
            $table->string('source', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedTinyInteger('currency_scale');
            $table->string('gateway_refund_reference')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->boolean('outcome_unknown')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_reference', 'status'], $name.'_payment_status_index');
            $table->unique(['gateway', 'gateway_refund_reference'], $name.'_gateway_ref_unique');
            $table->unique(['payment_reference', 'idempotency_key'], $name.'_idempotency_unique');
            $table->index(['status', 'created_at'], $name.'_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('refunds'));
    }
};
