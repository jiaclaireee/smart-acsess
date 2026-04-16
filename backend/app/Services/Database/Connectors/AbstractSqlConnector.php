<?php

namespace App\Services\Database\Connectors;

use App\Models\ConnectedDatabase;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorException;
use App\Services\DynamicConnectionFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

abstract class AbstractSqlConnector implements DatabaseConnector
{
    protected ?Connection $resolvedConnection = null;

    public function __construct(protected ConnectedDatabase $database)
    {
    }

    public function resourceType(): string
    {
        return 'table';
    }

    public function testConnection(): array
    {
        $this->connection()->getPdo();

        return [
            'message' => 'Connection successful.',
            'resource_type' => $this->resourceType(),
        ];
    }

    public function listResources(): array
    {
        return array_map(
            fn(object $row) => (string) $row->resource_name,
            $this->connection()->select($this->listResourcesSql(), $this->listResourcesBindings())
        );
    }

    public function getSchema(?string $resource = null): array
    {
        $resources = $resource !== null
            ? [$this->safeIdentifier($resource)]
            : $this->listResources();

        $schema = [];

        foreach ($resources as $resourceName) {
            $rows = $this->connection()->select($this->schemaSql(), $this->schemaBindings($resourceName));

            $schema[] = [
                'table' => $resourceName,
                'resource' => $resourceName,
                'columns' => array_map(fn(object $row) => [
                    'name' => (string) $row->column_name,
                    'type' => (string) $row->data_type,
                ], $rows),
            ];
        }

        return $schema;
    }

    public function previewRows(string $resource, array $filters = [], int $limit = 50): array
    {
        return $this->paginateRows($resource, $filters, 1, $limit)['rows'];
    }

    public function paginateRows(string $resource, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max($page, 1);
        $perPage = max(min($perPage, 100), 1);

        $query = $this->connection()->table($this->safeIdentifier($resource));
        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'rows' => collect($paginator->items())
                ->map(fn(object $row) => (array) $row)
                ->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function countRecords(string $resource, array $filters = []): int|float
    {
        $query = $this->connection()->table($this->safeIdentifier($resource));
        $this->applyFilters($query, $filters);

        return (int) $query->count();
    }

    public function aggregateByGroup(
        string $resource,
        string $groupColumn,
        string $metric = 'count',
        ?string $valueColumn = null,
        array $filters = [],
        int $limit = 10,
    ): array {
        $resource = $this->safeIdentifier($resource);
        $groupColumn = $this->safeIdentifier($groupColumn);
        $metric = strtolower($metric);
        $valueColumn = $valueColumn ? $this->safeIdentifier($valueColumn) : null;

        if (in_array($metric, ['sum', 'avg'], true) && !$valueColumn) {
            throw new DatabaseConnectorException('value_column is required for sum/avg.');
        }

        $wrappedGroup = $this->wrapIdentifier($groupColumn);
        $labelExpression = $this->groupLabelExpression($wrappedGroup);
        $rows = $this->connection()
            ->table($resource)
            ->when(true, function (Builder $query) use ($filters) {
                $this->applyFilters($query, $filters);
            })
            ->selectRaw(
                "COALESCE({$labelExpression}, 'Unknown') as bucket, "
                . $this->aggregateExpression($metric, $valueColumn) . ' as value'
            )
            ->groupBy(DB::raw($wrappedGroup))
            ->orderByDesc('value')
            ->limit(max(min($limit, 100), 1))
            ->get();

        return $rows->map(fn(object $row) => [
            'label' => (string) $row->bucket,
            'value' => is_numeric($row->value) ? (float) $row->value : 0.0,
        ])->all();
    }

    public function aggregateByDate(
        string $resource,
        string $dateColumn,
        string $metric = 'count',
        ?string $valueColumn = null,
        array $filters = [],
        string $period = 'daily',
        int $limit = 100,
    ): array {
        $resource = $this->safeIdentifier($resource);
        $dateColumn = $this->safeIdentifier($dateColumn);
        $metric = strtolower($metric);
        $valueColumn = $valueColumn ? $this->safeIdentifier($valueColumn) : null;

        if (in_array($metric, ['sum', 'avg'], true) && !$valueColumn) {
            throw new DatabaseConnectorException('value_column is required for sum/avg.');
        }

        $bucket = $this->bucketExpression($dateColumn, $period);
        if ($bucket === null) {
            return [];
        }

        $query = $this->connection()->table($resource);
        $this->applyFilters($query, array_merge($filters, ['date_column' => $dateColumn]));

        $rows = $query
            ->selectRaw("{$bucket} as bucket, " . $this->aggregateExpression($metric, $valueColumn) . ' as value')
            ->groupBy(DB::raw($bucket))
            ->orderBy(DB::raw($bucket))
            ->limit(max(min($limit, 500), 1))
            ->get();

        return $rows->map(fn(object $row) => [
            'label' => (string) $row->bucket,
            'value' => is_numeric($row->value) ? (float) $row->value : 0.0,
        ])->all();
    }

    public function getAggregateData(
        string $resource,
        string $metric,
        ?string $valueColumn = null,
        ?string $dateColumn = null,
        array $filters = [],
        string $period = 'none',
        int $limit = 50,
    ): array {
        $resource = $this->safeIdentifier($resource);
        $metric = strtolower($metric);

        if (!in_array($metric, ['count', 'sum', 'avg'], true)) {
            throw new DatabaseConnectorException('Unsupported metric.');
        }

        $valueColumn = $valueColumn ? $this->safeIdentifier($valueColumn) : null;
        $dateColumn = $dateColumn ? $this->safeIdentifier($dateColumn) : null;

        if (in_array($metric, ['sum', 'avg'], true) && !$valueColumn) {
            throw new DatabaseConnectorException('value_column is required for sum/avg.');
        }

        $summaryQuery = $this->connection()->table($resource);
        $this->applyFilters($summaryQuery, array_merge($filters, ['date_column' => $dateColumn]));
        $summaryValue = $summaryQuery
            ->selectRaw($this->aggregateExpression($metric, $valueColumn) . ' as value')
            ->value('value');

        $series = $dateColumn && $period !== 'none'
            ? collect($this->aggregateByDate($resource, $dateColumn, $metric, $valueColumn, $filters, $period, 500))
                ->map(fn(array $row) => ['x' => $row['label'], 'y' => $row['value']])
                ->all()
            : [];

        return [
            'summary' => is_numeric($summaryValue) ? $summaryValue + 0 : ($summaryValue ?? 0),
            'series' => $series,
            'rows' => $this->previewRows($resource, array_merge($filters, ['date_column' => $dateColumn]), $limit),
        ];
    }

    abstract protected function driver(): string;

    abstract protected function defaultPort(): int;

    abstract protected function listResourcesSql(): string;

    abstract protected function listResourcesBindings(): array;

    abstract protected function schemaSql(): string;

    abstract protected function schemaBindings(string $resource): array;

    abstract protected function bucketExpression(string $dateColumn, string $period): ?string;

    protected function connection(): Connection
    {
        if ($this->resolvedConnection instanceof Connection) {
            return $this->resolvedConnection;
        }

        $resolved = $this->database->resolvedConnectionDetails();
        $this->guardIncompleteManagedCredentials($resolved);
        $this->ensureDriverIsAvailable();

        $name = "ext_{$this->driver()}_{$this->database->id}";
        DynamicConnectionFactory::useSql($name, [
            'driver' => $this->driver(),
            'host' => $resolved['host'],
            'port' => $resolved['port'] ?? $this->defaultPort(),
            'database' => $resolved['database_name'],
            'username' => $resolved['username'],
            'password' => $resolved['password'],
            'extra' => $resolved['extra_config'],
        ]);

        $this->resolvedConnection = DB::connection($name);

        return $this->resolvedConnection;
    }

    protected function guardIncompleteManagedCredentials(array $resolved): void
    {
        if ($this->driver() !== ConnectedDatabase::TYPE_POSTGRESQL) {
            return;
        }

        $host = strtolower((string) ($resolved['host'] ?? ''));
        if (!str_contains($host, '.neon.tech')) {
            return;
        }

        $missing = [];

        if (empty($resolved['username'])) {
            $missing[] = 'username';
        }

        if (empty($resolved['password'])) {
            $missing[] = 'password';
        }

        if ($missing === []) {
            return;
        }

        $missingText = implode(' and ', $missing);

        throw new DatabaseConnectorException(
            "This Neon PostgreSQL connection is missing the {$missingText}. Edit the connection and enter the full Neon credentials. The username is the database role, and the password is the `npg_...` secret."
        );
    }

    protected function ensureDriverIsAvailable(): void
    {
        $supported = match ($this->driver()) {
            ConnectedDatabase::TYPE_POSTGRESQL => extension_loaded('pdo_pgsql'),
            ConnectedDatabase::TYPE_MYSQL => extension_loaded('pdo_mysql'),
            ConnectedDatabase::TYPE_SQL_SERVER => extension_loaded('sqlsrv') || extension_loaded('pdo_sqlsrv'),
            default => false,
        };

        if (!$supported) {
            throw new DatabaseConnectorException($this->driverAvailabilityMessage());
        }
    }

    protected function driverAvailabilityMessage(): string
    {
        return match ($this->driver()) {
            ConnectedDatabase::TYPE_SQL_SERVER => 'MS SQL support requires the sqlsrv or pdo_sqlsrv PHP extension.',
            ConnectedDatabase::TYPE_MYSQL => 'MySQL support requires the pdo_mysql PHP extension.',
            ConnectedDatabase::TYPE_POSTGRESQL => 'PostgreSQL support requires the pdo_pgsql PHP extension.',
            default => 'The selected driver is not available in this environment.',
        };
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        $dateColumn = $filters['date_column'] ?? null;
        if ($dateColumn) {
            $dateColumn = $this->safeIdentifier((string) $dateColumn);

            if (!empty($filters['from'])) {
                $query->where($dateColumn, '>=', $filters['from']);
            }

            if (!empty($filters['to'])) {
                $query->where($dateColumn, '<=', $filters['to']);
            }
        }

        foreach ((array) ($filters['equals'] ?? []) as $clause) {
            $column = $clause['column'] ?? null;
            if (!is_string($column) || trim($column) === '' || !array_key_exists('value', $clause)) {
                continue;
            }

            $query->where($this->safeIdentifier($column), '=', $clause['value']);
        }

        foreach ((array) ($filters['contains'] ?? []) as $clause) {
            $column = $clause['column'] ?? null;
            $value = isset($clause['value']) ? trim((string) $clause['value']) : '';

            if (!is_string($column) || trim($column) === '' || $value === '') {
                continue;
            }

            $wrappedColumn = $this->wrapIdentifier($this->safeIdentifier($column));
            $normalizedExpression = 'LOWER(' . $this->searchableTextExpression($wrappedColumn) . ')';
            $tokens = $this->searchTokens($value);

            $query->where(function (Builder $nested) use ($normalizedExpression, $tokens) {
                foreach ($tokens as $token) {
                    $nested->whereRaw($normalizedExpression . ' LIKE ?', ['%' . $token . '%']);
                }
            });
        }

        foreach ((array) ($filters['in'] ?? []) as $clause) {
            $column = $clause['column'] ?? null;
            $values = array_values(array_filter((array) ($clause['values'] ?? []), fn($value) => $value !== null && $value !== ''));

            if (!is_string($column) || trim($column) === '' || $values === []) {
                continue;
            }

            $query->whereIn($this->safeIdentifier($column), $values);
        }
    }

    protected function applySorting(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? null;
        if (!$sortBy) {
            return;
        }

        $direction = strtolower((string) ($filters['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($this->safeIdentifier((string) $sortBy), $direction);
    }

    protected function aggregateExpression(string $metric, ?string $valueColumn): string
    {
        return match ($metric) {
            'count' => 'COUNT(*)',
            'sum' => 'SUM(' . $this->wrapIdentifier($valueColumn) . ')',
            'avg' => 'AVG(' . $this->wrapIdentifier($valueColumn) . ')',
            default => throw new DatabaseConnectorException('Unsupported metric.'),
        };
    }

    protected function wrapIdentifier(?string $identifier): string
    {
        if ($identifier === null) {
            throw new DatabaseConnectorException('Missing identifier.');
        }

        return $this->connection()->getQueryGrammar()->wrap($identifier);
    }

    protected function groupLabelExpression(string $wrappedIdentifier): string
    {
        return $wrappedIdentifier;
    }

    protected function searchableTextExpression(string $wrappedIdentifier): string
    {
        return match ($this->driver()) {
            ConnectedDatabase::TYPE_POSTGRESQL => "CAST({$wrappedIdentifier} AS TEXT)",
            ConnectedDatabase::TYPE_SQL_SERVER => "CAST({$wrappedIdentifier} AS NVARCHAR(MAX))",
            default => "CAST({$wrappedIdentifier} AS CHAR)",
        };
    }

    protected function searchTokens(string $value): array
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return array_values(array_filter(
            explode(' ', trim($normalized)),
            fn(string $token) => $token !== ''
        ));
    }

    protected function safeIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new DatabaseConnectorException('Invalid table or column identifier.');
        }

        return $identifier;
    }
}
