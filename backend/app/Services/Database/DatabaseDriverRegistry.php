<?php

namespace App\Services\Database;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DatabaseDriverRegistry
{
    public function all(): array
    {
        return config('database_connectors.drivers', []);
    }

    public function registered(string $type): ?array
    {
        return $this->all()[$this->normalize($type)] ?? null;
    }

    public function connectorClass(string $type): ?string
    {
        return Arr::get($this->registered($type), 'connector');
    }

    public function label(string $type): string
    {
        return Arr::get($this->registered($type), 'label')
            ?? Str::of($type)->replace(['_', '-'], ' ')->title()->value();
    }

    public function defaultPort(string $type): ?int
    {
        $port = Arr::get($this->registered($type), 'default_port');

        return $port === null ? null : (int) $port;
    }

    public function resourceType(string $type): string
    {
        return Arr::get($this->registered($type), 'resource_type', 'table');
    }

    public function isRegistered(string $type): bool
    {
        return $this->registered($type) !== null;
    }

    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $key => $driver) {
            $options[] = [
                'key' => $key,
                'label' => $driver['label'] ?? $this->label($key),
                'default_port' => isset($driver['default_port']) ? (int) $driver['default_port'] : null,
                'resource_type' => $driver['resource_type'] ?? 'table',
                'registered' => true,
            ];
        }

        $custom = config('database_connectors.custom_option', []);
        $options[] = [
            'key' => $custom['key'] ?? '__custom__',
            'label' => $custom['label'] ?? 'Other / Custom',
            'default_port' => null,
            'resource_type' => 'table',
            'registered' => false,
            'requires_custom_key' => true,
        ];

        return $options;
    }

    public function normalize(string $type): string
    {
        return Str::of($type)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }
}
