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
        $table = PackageConfig::table('webhook_events');
        if (SchemaGuard::existsAndOwned('webhook_events')) {
            return;
        }

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 50);
            $table->string('event_id', 255);
            $table->string('event_type', 100);
            $table->string('payment_reference', 255);
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->string('status', 30);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
            $table->index('payment_reference');
            $table->index(['gateway', 'payment_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('webhook_events'));
    }
};
