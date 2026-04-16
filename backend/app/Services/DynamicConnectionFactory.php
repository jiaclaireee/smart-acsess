<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DynamicConnectionFactory
{
    public static function useSql(string $name, array $cfg): void
    {
        $driver = $cfg['driver'];
        $extra = $cfg['extra'] ?? [];

        $base = match ($driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $cfg['host'],
                'port' => $cfg['port'],
                'database' => $cfg['database'],
                'username' => $cfg['username'],
                'password' => $cfg['password'],
                'charset' => $extra['charset'] ?? 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => $extra['schema'] ?? 'public',
                'sslmode' => $extra['sslmode'] ?? 'prefer',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => $cfg['host'],
                'port' => $cfg['port'],
                'database' => $cfg['database'],
                'username' => $cfg['username'],
                'password' => $cfg['password'],
                'charset' => $extra['charset'] ?? 'utf8mb4',
                'collation' => $extra['collation'] ?? 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => [],
            ],
            'sqlsrv' => [
                'driver' => 'sqlsrv',
                'host' => $cfg['host'],
                'port' => $cfg['port'],
                'database' => $cfg['database'],
                'username' => $cfg['username'],
                'password' => $cfg['password'],
                'charset' => $extra['charset'] ?? 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'encrypt' => $extra['encrypt'] ?? 'yes',
                'trust_server_certificate' => $extra['trust_server_certificate'] ?? true,
            ],
            default => throw new \InvalidArgumentException("Unsupported SQL driver [{$driver}]"),
        };

        Config::set("database.connections.$name", $base);
        DB::purge($name);
    }
}
