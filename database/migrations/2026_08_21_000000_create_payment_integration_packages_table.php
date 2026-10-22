<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_integration_packages')) {
            return;
        }

        Schema::create('payment_integration_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package')->unique();
            $table->string('display_name');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_integration_packages');
    }
};
