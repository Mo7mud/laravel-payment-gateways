<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;

/*
 * The schema is built from the package's real migrations.
 *
 * Default: in-memory SQLite. Set DB_TEST_DRIVER=mysql|mariadb|pgsql (plus
 * DB_TEST_HOST, DB_TEST_PORT, DB_TEST_DATABASE, DB_TEST_USERNAME,
 * DB_TEST_PASSWORD) to run the same suite against a real server; the database
 * is wiped first. A second connection ("concurrent") is registered for
 * locking tests.
 */
$container = new Container;
Container::setInstance($container);

$container->instance('config', new ConfigRepository([
    'payment-gateway' => require dirname(__DIR__).'/config/payment-gateway.php',
]));

$driver = getenv('DB_TEST_DRIVER') ?: 'sqlite';
$connection = $driver === 'sqlite'
    ? ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']
    : [
        'driver' => $driver,
        'host' => getenv('DB_TEST_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_TEST_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
        'database' => getenv('DB_TEST_DATABASE') ?: 'payment_gateway_test',
        'username' => getenv('DB_TEST_USERNAME') ?: 'root',
        'password' => getenv('DB_TEST_PASSWORD') ?: '',
        'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
        'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
        'prefix' => '',
    ];

$capsule = new Capsule($container);
$capsule->addConnection($connection);
if ($driver !== 'sqlite') {
    $capsule->addConnection($connection, 'concurrent');
}
$capsule->setAsGlobal();
$capsule->bootEloquent();

$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', static fn () => $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);

if ($driver !== 'sqlite') {
    $capsule->getConnection()->getSchemaBuilder()->dropAllTables();
}

$migrations = glob(dirname(__DIR__).'/database/migrations/*.php') ?: [];
sort($migrations);
foreach ($migrations as $file) {
    (require $file)->up();
}
