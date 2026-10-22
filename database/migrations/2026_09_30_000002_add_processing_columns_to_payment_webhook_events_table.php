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
        SchemaGuard::assertOwned('webhook_events');
        $name = PackageConfig::table('webhook_events');

        Schema::table($name, function (Blueprint $table) use ($name): void {
            if (!Schema::hasColumn($name, 'status_reason')) {
                $table->string('status_reason', 500)->nullable()->after('status');
            }
            if (!Schema::hasColumn($name, 'attempts')) {
                $table->unsignedInteger('attempts')->default(1)->after('status_reason');
            }
            $table->index(['status', 'created_at'], $name . '_status_created_index');
        });
    }

    public function down(): void
    {
        $name = PackageConfig::table('webhook_events');

        Schema::table($name, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name . '_status_created_index');
            $table->dropColumn(['status_reason', 'attempts']);
        });
    }
};
