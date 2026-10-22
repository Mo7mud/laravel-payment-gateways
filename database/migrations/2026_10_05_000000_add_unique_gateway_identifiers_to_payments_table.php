<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mo7mud\LaravelPaymentGateway\Support\PackageConfig;
use Mo7mud\LaravelPaymentGateway\Support\SchemaGuard;

/**
 * Gateway identifiers locate payments from webhooks, so the database itself
 * must guarantee that each one belongs to a single payment.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'gateway_order_reference' => ['_gateway_order_ref_index', '_gateway_order_ref_unique'],
        'gateway_reference' => ['_gateway_gateway_ref_index', '_gateway_ref_unique'],
        'gateway_transaction_id' => ['_gateway_tx_index', '_gateway_tx_unique'],
    ];

    public function up(): void
    {
        SchemaGuard::assertOwned('payments');
        $name = PackageConfig::table('payments');

        // Not identifiers: Fawaterk sends transaction_id 0 for unsaved
        // transactions, and empty strings may exist from older versions.
        DB::table($name)->whereIn('gateway_transaction_id', ['0', ''])->update(['gateway_transaction_id' => null]);
        DB::table($name)->where('gateway_reference', '')->update(['gateway_reference' => null]);

        foreach (array_keys(self::COLUMNS) as $column) {
            $duplicates = DB::table($name)
                ->select('gateway', $column)
                ->whereNotNull($column)
                ->groupBy('gateway', $column)
                ->havingRaw('COUNT(*) > 1')
                ->limit(5)
                ->get();

            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException(sprintf(
                    'Cannot make %s.%s unique: duplicate values exist (%s). Resolve them and run the migration again.',
                    $name,
                    $column,
                    $duplicates->map(static fn (object $row): string => $row->gateway . ':' . $row->{$column})->implode(', '),
                ));
            }
        }

        Schema::table($name, function (Blueprint $table) use ($name): void {
            foreach (self::COLUMNS as $column => [$index, $unique]) {
                $table->dropIndex($name . $index);
                $table->unique(['gateway', $column], $name . $unique);
            }
        });
    }

    public function down(): void
    {
        $name = PackageConfig::table('payments');

        Schema::table($name, function (Blueprint $table) use ($name): void {
            foreach (self::COLUMNS as $column => [$index, $unique]) {
                $table->dropUnique($name . $unique);
                $table->index(['gateway', $column], $name . $index);
            }
        });
    }
};
