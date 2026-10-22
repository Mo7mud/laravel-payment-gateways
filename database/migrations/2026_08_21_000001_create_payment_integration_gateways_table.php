<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_integration_gateways')) {
            return;
        }

        Schema::create('payment_integration_gateways', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('payment_integration_packages')->cascadeOnDelete();
            $table->string('gateway', 100);
            $table->string('display_name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['package_id', 'gateway']);
            $table->index(['enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_integration_gateways');
    }
};
