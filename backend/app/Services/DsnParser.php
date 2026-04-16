<?php

namespace App\Services;

class DsnParser
{
    public static function parsePg(string $dsn): array
    {
        $parts = parse_url($dsn);
        if (!$parts || ($parts['scheme'] ?? '') !== 'postgresql') {
            throw new \InvalidArgumentException("Invalid PostgreSQL DSN. Use: postgresql://user:pass@host:5432/dbname?sslmode=prefer");
        }

        parse_str($parts['query'] ?? '', $query);

        return [
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int)($parts['port'] ?? 5432),
            'database' => ltrim($parts['path'] ?? '', '/'),
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
            'sslmode' => $query['sslmode'] ?? 'prefer',
        ];
    }
}
