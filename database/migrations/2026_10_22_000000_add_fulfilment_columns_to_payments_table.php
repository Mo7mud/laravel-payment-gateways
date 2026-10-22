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
            $table->timestamp('fulfilled_at')->nullable()->after('paid_at');
            $table->unsignedInteger('fulfilment_dispatches')->default(0)->after('fulfilled_at');
            $table->timestamp('next_fulfilment_dispatch_at')->nullable()->after('fulfilment_dispatches');
            $table->index(['fulfilled_at', 'paid_at'], $name . '_fulfilment_index');
        });

        // Payments paid before fulfilment tracking existed were handled by the
        // application already; never re-raise PaymentSucceeded for them.
        DB::table($name)->whereNotNull('paid_at')->update(['fulfilled_at' => DB::raw('paid_at')]);
    }

    public function down(): void
    {
        $name = PackageConfig::table('payments');

        Schema::table($name, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name . '_fulfilment_index');
            $table->dropColumn(['fulfilled_at', 'fulfilment_dispatches', 'next_fulfilment_dispatch_at']);
        });
    }
};
