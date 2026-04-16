<?php

namespace App\Services\Database\Contracts;

interface DatabaseConnector
{
    public function resourceType(): string;

    public function testConnection(): array;

    public function listResources(): array;

    public function getSchema(?string $resource = null): array;

    public function previewRows(string $resource, array $filters = [], int $limit = 50): array;

    public function paginateRows(string $resource, array $filters = [], int $page = 1, int $perPage = 25): array;

    public function countRecords(string $resource, array $filters = []): int|float;

    public function aggregateByGroup(
        string $resource,
        string $groupColumn,
        string $metric = 'count',
        ?string $valueColumn = null,
        array $filters = [],
        int $limit = 10,
    ): array;

    public function aggregateByDate(
        string $resource,
        string $dateColumn,
        string $metric = 'count',
        ?string $valueColumn = null,
        array $filters = [],
        string $period = 'daily',
        int $limit = 100,
    ): array;

    public function getAggregateData(
        string $resource,
        string $metric,
        ?string $valueColumn = null,
        ?string $dateColumn = null,
        array $filters = [],
        string $period = 'none',
        int $limit = 50,
    ): array;
}
