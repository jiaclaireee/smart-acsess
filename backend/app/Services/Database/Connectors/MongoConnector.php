<?php

namespace App\Services\Database\Connectors;

use App\Models\ConnectedDatabase;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class MongoConnector implements DatabaseConnector
{
    protected ?object $resolvedClient = null;

    protected ?object $resolvedDatabase = null;

    public function __construct(protected ConnectedDatabase $database)
    {
    }

    public function resourceType(): string
    {
        return 'collection';
    }

    public function testConnection(): array
    {
        $this->client()->listDatabases(['nameOnly' => true]);

        return [
            'message' => 'Connection successful.',
            'resource_type' => $this->resourceType(),
        ];
    }

    public function listResources(): array
    {
        $collections = $this->database()->listCollections();
        $names = [];

        foreach ($collections as $collection) {
            $names[] = $collection->getName();
        }

        sort($names);

        return $names;
    }

    public function getSchema(?string $resource = null): array
    {
        $resources = $resource !== null ? [$resource] : $this->listResources();
        $sampleSize = (int) ($this->database->extra_config['sample_size'] ?? 20);
        $schema = [];

        foreach ($resources as $collectionName) {
            $collection = $this->database()->selectCollection($collectionName);
            $cursor = $collection->find([], ['limit' => $sampleSize]);
            $fieldTypes = [];

            foreach ($cursor as $document) {
                foreach ($this->normalizeDocument((array) $document) as $field => $value) {
                    $fieldTypes[$field] ??= [];
                    $fieldTypes[$field][get_debug_type($value)] = true;
                }
            }

            $columns = [];
            foreach ($fieldTypes as $field => $types) {
                $columns[] = [
                    'name' => $field,
                    'type' => implode('|', array_keys($types)),
                ];
            }

            $schema[] = [
                'table' => $collectionName,
                'resource' => $collectionName,
                'columns' => $columns,
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

        $collection = $this->database()->selectCollection($resource);
        $query = $this->mongoMatchFilter($filters);
        $total = $collection->countDocuments($query);
        $rows = [];

        foreach ($collection->find($query, [
            'limit' => $perPage,
            'skip' => ($page - 1) * $perPage,
            'sort' => $this->mongoSort($filters),
        ]) as $document) {
            $rows[] = $this->normalizeDocument((array) $document);
        }

        $lastPage = max((int) ceil($total / $perPage), 1);

        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : null,
                'to' => $total > 0 ? min($page * $perPage, $total) : null,
            ],
        ];
    }

    public function countRecords(string $resource, array $filters = []): int|float
    {
        return $this->database()
            ->selectCollection($resource)
            ->countDocuments($this->mongoMatchFilter($filters));
    }

    public function aggregateByGroup(
        string $resource,
        string $groupColumn,
        string $metric = 'count',
        ?string $valueColumn = null,
        array $filters = [],
        int $limit = 10,
    ): array {
        $metric = strtolower($metric);
        if (in_array($metric, ['sum', 'avg'], true) && !$valueColumn) {
            throw new DatabaseConnectorException('value_column is required for sum/avg.');
        }

        $collection = $this->database()->selectCollection($resource);
        $pipeline = [];
        $match = $this->mongoMatchFilter($filters);

        if ($match !== []) {
            $pipeline[] = ['$match' => $match];
        }

        $pipeline[] = [
            '$group' => [
                '_id' => ['$ifNull' => ['$' . $groupColumn, 'Unknown']],
                'value' => $metric === 'count'
                    ? ['$sum' => 1]
                    : ['$' . $metric => '$' . $valueColumn],
            ],
        ];
        $pipeline[] = ['$sort' => ['value' => -1, '_id' => 1]];
        $pipeline[] = ['$limit' => max(min($limit, 100), 1)];

        return array_map(function ($row) {
            $label = $row->_id;
            if (is_array($label) || is_object($label)) {
                $label = json_encode($label);
            }

            return [
                'label' => (string) $label,
                'value' => is_numeric($row->value) ? (float) $row->value : 0.0,
            ];
        }, $collection->aggregate($pipeline)->toArray());
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
        $metric = strtolower($metric);
        if (in_array($metric, ['sum', 'avg'], true) && !$valueColumn) {
            throw new DatabaseConnectorException('value_column is required for sum/avg.');
        }

        $collection = $this->database()->selectCollection($resource);
        $pipeline = [];
        $match = $this->mongoMatchFilter(array_merge($filters, ['date_column' => $dateColumn]));

        if ($match !== []) {
            $pipeline[] = ['$match' => $match];
        }

        $pipeline[] = [
            '$group' => [
                '_id' => $this->mongoBucketExpression($dateColumn, $period),
                'value' => $metric === 'count'
                    ? ['$sum' => 1]
                    : ['$' . $metric => '$' . $valueColumn],
            ],
        ];
        $pipeline[] = ['$sort' => ['_id' => 1]];
        $pipeline[] = ['$limit' => max(min($limit, 500), 1)];

        return array_map(function ($row) {
            $label = is_scalar($row->_id) ? (string) $row->_id : json_encode($row->_id);

            return [
                'label' => $label,
                'value' => is_numeric($row->value) ? (float) $row->value : 0.0,
            ];
        }, $collection->aggregate($pipeline)->toArray());
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
        $collection = $this->database()->selectCollection($resource);
        $metric = strtolower($metric);

        if (in_array($metric, ['sum', 'avg'], true) && !$valueColumn) {
            throw new DatabaseConnectorException('value_column is required for sum/avg.');
        }

        $match = $this->mongoMatchFilter(array_merge($filters, ['date_column' => $dateColumn]));
        $summary = 0;

        if ($metric === 'count') {
            $summary = $collection->countDocuments($match);
        } else {
            $groupField = '$' . $valueColumn;
            $pipeline = [];
            if ($match !== []) {
                $pipeline[] = ['$match' => $match];
            }
            $pipeline[] = [
                '$group' => [
                    '_id' => null,
                    'value' => ['$' . $metric => $groupField],
                ],
            ];

            $results = $collection->aggregate($pipeline)->toArray();
            $summary = $results[0]->value ?? 0;
        }

        $series = $dateColumn && $period !== 'none'
            ? array_map(
                fn(array $row) => ['x' => $row['label'], 'y' => $row['value']],
                $this->aggregateByDate($resource, $dateColumn, $metric, $valueColumn, $filters, $period, 500)
            )
            : [];

        return [
            'summary' => is_numeric($summary) ? $summary + 0 : 0,
            'series' => $series,
            'rows' => $this->previewRows($resource, array_merge($filters, ['date_column' => $dateColumn]), $limit),
        ];
    }

    private function client(): object
    {
        if ($this->resolvedClient !== null) {
            return $this->resolvedClient;
        }

        if (!class_exists(\MongoDB\Client::class)) {
            throw new DatabaseConnectorException('MongoDB support requires the mongodb/mongodb package and ext-mongodb.');
        }

        $extra = $this->database->extra_config;
        $scheme = !empty($extra['srv']) ? 'mongodb+srv' : 'mongodb';
        $credentials = '';

        if (!empty($this->database->username)) {
            $credentials = rawurlencode($this->database->username);
            if ($this->database->password !== null) {
                $credentials .= ':' . rawurlencode($this->database->password);
            }
            $credentials .= '@';
        }

        $options = $extra;
        unset($options['srv'], $options['sample_size']);

        $authority = $this->database->host;
        if ($scheme === 'mongodb') {
            $authority .= ':' . ($this->database->port ?? 27017);
        }

        $uri = "{$scheme}://{$credentials}{$authority}";
        if ($options !== []) {
            $uri .= '/?' . http_build_query($options);
        }

        $this->resolvedClient = new \MongoDB\Client($uri);

        return $this->resolvedClient;
    }

    private function database(): object
    {
        if ($this->resolvedDatabase !== null) {
            return $this->resolvedDatabase;
        }

        $this->resolvedDatabase = $this->client()->selectDatabase($this->database->database_name);

        return $this->resolvedDatabase;
    }

    private function mongoMatchFilter(array $filters): array
    {
        $conditions = [];
        $dateColumn = $filters['date_column'] ?? null;
        if ($dateColumn) {
            $range = [];
            if (!empty($filters['from'])) {
                $range['$gte'] = new UTCDateTime(strtotime($filters['from']) * 1000);
            }
            if (!empty($filters['to'])) {
                $range['$lte'] = new UTCDateTime(strtotime($filters['to'] . ' 23:59:59') * 1000);
            }

            if ($range !== []) {
                $conditions[] = [$dateColumn => $range];
            }
        }

        foreach ((array) ($filters['equals'] ?? []) as $clause) {
            $column = $clause['column'] ?? null;
            if (!is_string($column) || trim($column) === '' || !array_key_exists('value', $clause)) {
                continue;
            }

            $conditions[] = [$column => $clause['value']];
        }

        foreach ((array) ($filters['contains'] ?? []) as $clause) {
            $column = $clause['column'] ?? null;
            $value = isset($clause['value']) ? trim((string) $clause['value']) : '';

            if (!is_string($column) || trim($column) === '' || $value === '') {
                continue;
            }

            $conditions[] = [
                $column => [
                    '$regex' => preg_quote($value, '/'),
                    '$options' => 'i',
                ],
            ];
        }

        foreach ((array) ($filters['in'] ?? []) as $clause) {
            $column = $clause['column'] ?? null;
            $values = array_values(array_filter((array) ($clause['values'] ?? []), fn($value) => $value !== null && $value !== ''));

            if (!is_string($column) || trim($column) === '' || $values === []) {
                continue;
            }

            $conditions[] = [$column => ['$in' => $values]];
        }

        if ($conditions === []) {
            return [];
        }

        return count($conditions) === 1 ? $conditions[0] : ['$and' => $conditions];
    }

    private function mongoBucketExpression(string $dateColumn, string $period): array|string
    {
        $field = '$' . $dateColumn;

        return match ($period) {
            'daily' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => $field]],
            'weekly' => ['$dateToString' => ['format' => '%G-W%V', 'date' => $field]],
            'monthly' => ['$dateToString' => ['format' => '%Y-%m-01', 'date' => $field]],
            'quarterly' => [
                '$concat' => [
                    ['$toString' => ['$year' => $field]],
                    '-Q',
                    ['$toString' => ['$ceil' => ['$divide' => [['$month' => $field], 3]]]],
                ],
            ],
            'semiannual' => [
                '$concat' => [
                    ['$toString' => ['$year' => $field]],
                    '-H',
                    ['$cond' => [['$lte' => [['$month' => $field], 6]], '1', '2']],
                ],
            ],
            'annual' => ['$dateToString' => ['format' => '%Y-01-01', 'date' => $field]],
            default => 'all',
        };
    }

    private function mongoSort(array $filters): array
    {
        $sortBy = $filters['sort_by'] ?? null;
        if (!$sortBy) {
            return [];
        }

        $direction = strtolower((string) ($filters['sort_direction'] ?? 'asc')) === 'desc' ? -1 : 1;

        return [(string) $sortBy => $direction];
    }

    private function normalizeDocument(array $document): array
    {
        $normalized = [];

        foreach ($document as $key => $value) {
            $normalized[$key] = match (true) {
                $value instanceof UTCDateTime => $value->toDateTime()->format(DATE_ATOM),
                $value instanceof ObjectId => (string) $value,
                is_object($value) => $this->normalizeDocument((array) $value),
                is_array($value) => array_map(fn($item) => is_array($item) ? $this->normalizeDocument($item) : $item, $value),
                default => $value,
            };
        }

        return $normalized;
    }
}
