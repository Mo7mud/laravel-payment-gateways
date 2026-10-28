<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Support;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Keeps the package migrations away from tables that belong to the host
 * application. A table with the configured name is only reused when it
 * carries the package's own columns; anything else stops the migration with
 * an explanation instead of silently altering a foreign table.
 */
final class SchemaGuard
{
    /** Columns that identify each package table. */
    public const SIGNATURES = [
        'payments' => ['reference', 'amount_minor', 'currency_scale'],
        'refunds' => ['uuid', 'payment_reference', 'amount_minor'],
        'webhook_events' => ['event_id', 'payload_hash'],
    ];

    /** Environment variable that renames each table. */
    public const ENV_KEYS = [
        'payments' => 'PAYMENT_GATEWAY_PAYMENTS_TABLE',
        'refunds' => 'PAYMENT_GATEWAY_REFUNDS_TABLE',
        'webhook_events' => 'PAYMENT_GATEWAY_WEBHOOK_EVENTS_TABLE',
    ];

    /**
     * For create migrations: false when the table does not exist yet (create
     * it), true when it exists and is the package's own (skip). Throws when a
     * table with that name belongs to someone else.
     */
    public static function existsAndOwned(string $logicalName): bool
    {
        $table = PackageConfig::table($logicalName);
        if (! Schema::hasTable($table)) {
            return false;
        }

        self::assertOwned($logicalName);

        return true;
    }

    /** For alter migrations: the table must exist and be the package's own. */
    public static function assertOwned(string $logicalName): void
    {
        $table = PackageConfig::table($logicalName);

        if (! Schema::hasTable($table)) {
            throw new RuntimeException(sprintf(
                'Table [%s] does not exist; run the package create migrations first.',
                $table,
            ));
        }

        foreach (self::SIGNATURES[$logicalName] ?? [] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException(sprintf(
                    'Table [%s] already exists but was not created by mo7mud/laravel-payment-gateway '
                    .'(column [%s] is missing). Set %s to an unused table name and run the migrations again.',
                    $table,
                    $column,
                    self::ENV_KEYS[$logicalName] ?? 'the table name in payment-gateway.tables',
                ));
            }
        }
    }
}
