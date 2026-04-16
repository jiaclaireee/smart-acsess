<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ConnectedDatabase extends Model
{
    public const TYPE_POSTGRESQL = 'pgsql';
    public const TYPE_MYSQL = 'mysql';
    public const TYPE_MARIADB = 'mariadb';
    public const TYPE_SQL_SERVER = 'sqlsrv';
    public const TYPE_MONGODB = 'mongodb';

    protected $fillable = [
        'name',
        'type',
        'host',
        'port',
        'database_name',
        'username',
        'password_encrypted',
        'extra_config_encrypted',
        'connection_string_encrypted',
    ];

    protected $hidden = [
        'password_encrypted',
        'extra_config_encrypted',
        'connection_string_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }

    public function setConnectionStringAttribute(string $dsn): void
    {
        $this->attributes['connection_string_encrypted'] = Crypt::encryptString($dsn);
    }

    public function getConnectionStringAttribute(): string
    {
        return Crypt::decryptString($this->connection_string_encrypted);
    }

    public function setPasswordAttribute(?string $password): void
    {
        if ($password === null || $password === '') {
            return;
        }

        $this->attributes['password_encrypted'] = Crypt::encryptString($password);
    }

    public function getPasswordAttribute(): ?string
    {
        if (empty($this->attributes['password_encrypted'])) {
            return null;
        }

        return Crypt::decryptString($this->attributes['password_encrypted']);
    }

    public function setExtraConfigAttribute(array|string|null $extraConfig): void
    {
        if ($extraConfig === null || $extraConfig === '') {
            $this->attributes['extra_config_encrypted'] = null;
            return;
        }

        $payload = is_array($extraConfig) ? $extraConfig : json_decode($extraConfig, true);
        $this->attributes['extra_config_encrypted'] = Crypt::encryptString(json_encode($payload ?? [], JSON_THROW_ON_ERROR));
    }

    public function getExtraConfigAttribute(): array
    {
        if (empty($this->attributes['extra_config_encrypted'])) {
            return [];
        }

        $payload = Crypt::decryptString($this->attributes['extra_config_encrypted']);

        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }

    public function clearPassword(): void
    {
        $this->attributes['password_encrypted'] = null;
    }

    public function clearExtraConfig(): void
    {
        $this->attributes['extra_config_encrypted'] = null;
    }

    public function passwordConfigured(): bool
    {
        return !empty($this->attributes['password_encrypted']);
    }

    public function extraConfigConfigured(): bool
    {
        return !empty($this->attributes['extra_config_encrypted']);
    }

    public function resourceLabel(): string
    {
        return app(\App\Services\Database\DatabaseDriverRegistry::class)->resourceType($this->type);
    }

    public static function normalizeConnectionAttributes(array $attributes): array
    {
        $host = trim((string) ($attributes['host'] ?? ''));
        if ($host === '') {
            return $attributes;
        }

        $isDsn = preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $host) === 1;
        $candidate = $isDsn ? $host : 'placeholder://' . $host;
        $parts = parse_url($candidate);

        if ($parts === false || empty($parts['host'])) {
            return $attributes;
        }

        $attributes['host'] = $parts['host'];

        if (isset($parts['port'])) {
            $attributes['port'] = (int) $parts['port'];
        }

        if (empty($attributes['username']) && !empty($parts['user'])) {
            $attributes['username'] = rawurldecode($parts['user']);
        }

        if (empty($attributes['password']) && !empty($parts['pass'])) {
            $attributes['password'] = rawurldecode($parts['pass']);
        }

        if (empty($attributes['database_name']) && !empty($parts['path'])) {
            $attributes['database_name'] = ltrim($parts['path'], '/');
        }

        if ($isDsn && !empty($parts['query'])) {
            parse_str($parts['query'], $query);

            if ($query !== []) {
                $existingExtra = is_array($attributes['extra_config'] ?? null)
                    ? $attributes['extra_config']
                    : [];

                $attributes['extra_config'] = array_replace($query, $existingExtra);
            }
        }

        return $attributes;
    }

    public function resolvedConnectionDetails(): array
    {
        return self::normalizeConnectionAttributes([
            'host' => $this->attributes['host'] ?? '',
            'port' => $this->attributes['port'] ?? null,
            'database_name' => $this->attributes['database_name'] ?? '',
            'username' => $this->attributes['username'] ?? null,
            'password' => $this->password,
            'extra_config' => $this->extra_config,
        ]);
    }

    public function extraConfigKeys(): array
    {
        return array_keys($this->resolvedConnectionDetails()['extra_config'] ?? []);
    }

    public function publicMetadata(): array
    {
        $registry = app(\App\Services\Database\DatabaseDriverRegistry::class);
        $resolved = $this->resolvedConnectionDetails();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $registry->label($this->type),
            'host' => $resolved['host'],
            'port' => $resolved['port'],
            'default_port' => $registry->defaultPort($this->type),
            'database_name' => $resolved['database_name'],
            'username' => $resolved['username'],
            'password_configured' => !empty($resolved['password']),
            'extra_config_configured' => ($resolved['extra_config'] ?? []) !== [],
            'extra_config_keys' => $this->extraConfigKeys(),
            'resource_label' => $this->resourceLabel(),
            'connector_registered' => $registry->isRegistered($this->type),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
