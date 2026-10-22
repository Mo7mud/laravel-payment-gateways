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
        $table = PackageConfig::table('payments');
        if (SchemaGuard::existsAndOwned('payments')) {
            return;
        }

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('gateway', 50);
            $table->string('status', 50);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedTinyInteger('currency_scale');
            $table->string('gateway_reference')->nullable()->index();
            $table->string('gateway_transaction_id')->nullable()->index();
            $table->string('gateway_status')->nullable();
            $table->text('checkout_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('payments'));
    }
};
