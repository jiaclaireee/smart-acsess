<?php

namespace App\Services;

use App\Models\ConnectedDatabase;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class DatabaseReportingService
{
    public function buildReport(ConnectedDatabase $database, DatabaseConnector $connector, array $input): array
    {
        $resources = $connector->listResources();
        sort($resources);

        $resource = $this->normalizeResource($input['resource'] ?? null, $resources);
        $graphType = $this->normalizeGraphType($input['graph_type'] ?? 'table');
        $period = $this->normalizePeriod($input['period'] ?? 'none');
        $page = max((int) ($input['page'] ?? 1), 1);
        $perPage = max(min((int) ($input['per_page'] ?? 25), 100), 1);
        $sortDirection = strtolower((string) ($input['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($resource === null) {
            return $this->buildDatabaseOverview(
                $database,
                $connector,
                $resources,
                $graphType,
                $page,
                $perPage,
            );
        }

        $schema = $connector->getSchema($resource)[0] ?? [
            'resource' => $resource,
            'table' => $resource,
            'columns' => [],
        ];
        $columns = $schema['columns'] ?? [];
        $dateColumn = $this->pickColumn($columns, $input['date_column'] ?? null, fn(array $column) => $this->isDateColumn($column));
        $groupColumn = $this->pickColumnName($columns, $input['group_column'] ?? null)
            ?? $this->pickColumn($columns, null, fn(array $column) => $this->isGroupableColumn($column));
        $sortBy = $this->pickColumnName($columns, $input['sort_by'] ?? null);

        $dateFilters = $dateColumn
            ? array_filter([
                'date_column' => $dateColumn,
                'from' => $input['from'] ?? null,
                'to' => $input['to'] ?? null,
            ], fn($value) => $value !== null && $value !== '')
            : [];

        $tableFilters = array_merge($dateFilters, array_filter([
            'sort_by' => $sortBy,
            'sort_direction' => $sortDirection,
        ], fn($value) => $value !== null && $value !== ''));

        $table = $connector->paginateRows($resource, $tableFilters, $page, $perPage);
        $totalRecords = (int) $connector->countRecords($resource);
        $filteredRecords = (int) $connector->countRecords($resource, $dateFilters);

        $warnings = [];
        if (($input['from'] ?? null) || ($input['to'] ?? null) || $period !== 'none') {
            if (!$dateColumn) {
                $warnings[] = 'No date-like field was detected on the selected resource, so date filters and report periods were ignored.';
            }
        }

        $chart = $this->buildResourceChart(
            connector: $connector,
            resource: $resource,
            dateColumn: $dateColumn,
            groupColumn: $groupColumn,
            graphType: $graphType,
            period: $period,
            filters: $dateFilters,
        );

        if (($chart['meta']['mode'] ?? null) === 'empty' && $graphType !== 'table') {
            $warnings[] = $chart['empty_message'] ?? 'No chart-ready grouping could be generated for the selected resource.';
        }

        $dateFields = array_values(array_map(
            fn(array $column) => $column['name'],
            array_filter($columns, fn(array $column) => $this->isDateColumn($column))
        ));

        return [
            'database' => $database->publicMetadata(),
            'resource_type' => $connector->resourceType(),
            'resources' => $resources,
            'selected_resource' => $resource,
            'graph_type' => $graphType,
            'period' => $dateColumn ? $period : 'none',
            'warnings' => $warnings,
            'schema' => [
                'resource' => $resource,
                'columns' => $columns,
                'date_fields' => $dateFields,
                'detected' => [
                    'date_column' => $dateColumn,
                    'group_column' => $groupColumn,
                ],
            ],
            'kpis' => [
                [
                    'label' => 'Filtered Records',
                    'value' => $filteredRecords,
                    'hint' => $dateFilters === [] ? 'No date filter applied.' : 'Rows matching the active date range.',
                ],
                [
                    'label' => 'Total Records',
                    'value' => $totalRecords,
                    'hint' => 'All rows or documents in the selected resource.',
                ],
                [
                    'label' => 'Columns',
                    'value' => count($columns),
                    'hint' => 'Detected dynamically from the selected schema.',
                ],
                [
                    'label' => 'Date Field',
                    'value' => $dateColumn ?? 'Not Detected',
                    'hint' => $dateColumn ? 'Used for date filtering and period grouping when applicable.' : 'Reporting stays unfiltered when no date field is available.',
                ],
            ],
            'chart' => $chart,
            'table' => [
                'columns' => $this->resolveTableColumns($table['rows'], $columns),
                'rows' => $table['rows'],
                'pagination' => $table['pagination'],
            ],
            'capabilities' => [
                'supports_date_filtering' => $dateColumn !== null,
                'supports_period_aggregation' => $dateColumn !== null,
                'supports_grouped_charts' => $groupColumn !== null || $dateColumn !== null,
            ],
        ];
    }

    public function buildDrilldown(ConnectedDatabase $database, DatabaseConnector $connector, array $input): array
    {
        $resources = $connector->listResources();
        sort($resources);

        $resource = $this->normalizeResource($input['resource'] ?? null, $resources);
        $chartMode = $this->normalizeChartMode($input['chart_mode'] ?? null);
        $bucketLabel = trim((string) ($input['bucket_label'] ?? ''));
        $period = $this->normalizePeriod($input['period'] ?? 'none');
        $page = max((int) ($input['page'] ?? 1), 1);
        $perPage = max(min((int) ($input['per_page'] ?? 25), 100), 1);
        $sortDirection = strtolower((string) ($input['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($bucketLabel === '') {
            throw new DatabaseConnectorException('Select a chart value first to load its matching rows.');
        }

        if ($chartMode === 'resource_overview') {
            $resource = $this->normalizeResource($bucketLabel, $resources);
            $schema = $connector->getSchema($resource)[0] ?? [
                'resource' => $resource,
                'table' => $resource,
                'columns' => [],
            ];
            $columns = $schema['columns'] ?? [];
            $sortBy = $this->pickColumnName($columns, $input['sort_by'] ?? null);
            $tableFilters = array_filter([
                'sort_by' => $sortBy,
                'sort_direction' => $sortDirection,
            ], fn($value) => $value !== null && $value !== '');
            $table = $connector->paginateRows($resource, $tableFilters, $page, $perPage);

            return [
                'title' => 'Rows behind ' . $bucketLabel,
                'description' => 'Showing the rows behind the selected ' . $connector->resourceType() . ' from the overview chart.',
                'selection' => [
                    'label' => $bucketLabel,
                    'mode' => $chartMode,
                    'resource' => $resource,
                    'resource_type' => $connector->resourceType(),
                    'group_by' => $connector->resourceType(),
                    'period' => 'none',
                ],
                'table' => [
                    'columns' => $this->resolveTableColumns($table['rows'], $columns),
                    'rows' => $table['rows'],
                    'pagination' => $table['pagination'],
                ],
            ];
        }

        if ($resource === null) {
            throw new DatabaseConnectorException('Select a table or collection first before drilling into chart data.');
        }

        $schema = $connector->getSchema($resource)[0] ?? [
            'resource' => $resource,
            'table' => $resource,
            'columns' => [],
        ];
        $columns = $schema['columns'] ?? [];
        $dateColumn = $this->pickColumn($columns, $input['date_column'] ?? null, fn(array $column) => $this->isDateColumn($column));
        $groupColumn = $this->pickColumnName($columns, $input['chart_group_by'] ?? null)
            ?? $this->pickColumnName($columns, $input['group_column'] ?? null)
            ?? $this->pickColumn($columns, null, fn(array $column) => $this->isGroupableColumn($column));
        $sortBy = $this->pickColumnName($columns, $input['sort_by'] ?? null);

        $dateFilters = $this->buildDateFilters($dateColumn, $input);
        $drilldownFilters = $dateFilters;
        $groupBy = null;

        if ($chartMode === 'group') {
            $groupBy = $groupColumn;

            if (!$groupBy) {
                throw new DatabaseConnectorException('The selected chart does not expose a drill-down grouping column.');
            }

            $drilldownFilters['equals'] = [[
                'column' => $groupBy,
                'value' => $bucketLabel,
            ]];
        }

        if ($chartMode === 'date') {
            $groupBy = $dateColumn ?? $this->pickColumnName($columns, $input['chart_group_by'] ?? null);

            if (!$groupBy) {
                throw new DatabaseConnectorException('The selected chart does not expose a drill-down date column.');
            }

            $drilldownFilters = $this->mergeDateRanges(
                $dateFilters,
                $this->buildBucketDateFilters($groupBy, $bucketLabel, $period),
            );
        }

        $tableFilters = array_merge($drilldownFilters, array_filter([
            'sort_by' => $sortBy,
            'sort_direction' => $sortDirection,
        ], fn($value) => $value !== null && $value !== ''));

        $table = $connector->paginateRows($resource, $tableFilters, $page, $perPage);

        return [
            'title' => 'Rows behind ' . $bucketLabel,
            'description' => match ($chartMode) {
                'group' => 'Showing rows where ' . $groupBy . ' matches the selected chart segment.',
                'date' => 'Showing rows that fall inside the selected ' . $this->periodLabel($period) . ' bucket.',
                default => 'Showing rows behind the selected chart segment.',
            },
            'selection' => [
                'label' => $bucketLabel,
                'mode' => $chartMode,
                'resource' => $resource,
                'resource_type' => $connector->resourceType(),
                'group_by' => $groupBy,
                'period' => $chartMode === 'date' ? $period : 'none',
            ],
            'table' => [
                'columns' => $this->resolveTableColumns($table['rows'], $columns),
                'rows' => $table['rows'],
                'pagination' => $table['pagination'],
            ],
        ];
    }

    private function buildDatabaseOverview(
        ConnectedDatabase $database,
        DatabaseConnector $connector,
        array $resources,
        string $graphType,
        int $page,
        int $perPage,
    ): array {
        $rows = [];
        $warnings = [];

        foreach ($resources as $resource) {
            try {
                $schema = $connector->getSchema($resource)[0] ?? ['columns' => []];
                $columns = $schema['columns'] ?? [];
                $dateFields = array_values(array_map(
                    fn(array $column) => $column['name'],
                    array_filter($columns, fn(array $column) => $this->isDateColumn($column))
                ));

                $rows[] = [
                    'resource' => $resource,
                    'record_count' => (int) $connector->countRecords($resource),
                    'column_count' => count($columns),
                    'date_fields' => implode(', ', $dateFields),
                ];
            } catch (Throwable $exception) {
                Log::warning('Skipping resource during dashboard whole-database overview build.', [
                    'database_id' => $database->id,
                    'resource' => $resource,
                    'error' => $exception->getMessage(),
                ]);

                $warnings[] = 'Skipped ' . $resource . ' because its structure could not be summarized.';
            }
        }

        usort($rows, function (array $left, array $right) {
            if ($left['record_count'] === $right['record_count']) {
                return strcmp($left['resource'], $right['resource']);
            }

            return $right['record_count'] <=> $left['record_count'];
        });

        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);
        $topRows = array_slice($rows, 0, min(count($rows), 10));
        $dateEnabledResources = count(array_filter($rows, fn(array $row) => $row['date_fields'] !== ''));

        return [
            'database' => $database->publicMetadata(),
            'resource_type' => $connector->resourceType(),
            'resources' => $resources,
            'selected_resource' => null,
            'graph_type' => $graphType,
            'period' => 'none',
            'warnings' => array_values(array_filter([
                'Select a specific ' . $connector->resourceType() . ' to enable row-level filters and date-based aggregation.',
                ...$warnings,
                count($rows) === 0 ? 'No table or collection summaries could be prepared for whole-database mode.' : null,
            ])),
            'schema' => null,
            'kpis' => [
                [
                    'label' => ucfirst($connector->resourceType()) . 's',
                    'value' => count($rows),
                    'hint' => 'Available resources in the selected connection.',
                ],
                [
                    'label' => 'Total Records',
                    'value' => array_sum(array_column($rows, 'record_count')),
                    'hint' => 'Sum of records across all discovered resources.',
                ],
                [
                    'label' => 'Date-Ready Resources',
                    'value' => $dateEnabledResources,
                    'hint' => 'Resources with at least one date-like field.',
                ],
                [
                    'label' => 'Connection Type',
                    'value' => $database->type_label ?? strtoupper($database->type),
                    'hint' => 'Report output adapts to the selected driver.',
                ],
            ],
            'chart' => [
                'type' => $graphType === 'table' ? 'bar' : $graphType,
                'title' => 'Record counts by ' . $connector->resourceType(),
                'labels' => array_map(fn(array $row) => $row['resource'], $topRows),
                'series' => array_map(fn(array $row) => $row['record_count'], $topRows),
                'empty_message' => count($topRows) === 0 ? 'No resources were found for the selected database.' : null,
                'meta' => [
                    'mode' => count($topRows) === 0 ? 'empty' : 'resource_overview',
                    'group_by' => $connector->resourceType(),
                ],
            ],
            'table' => [
                'columns' => ['resource', 'record_count', 'column_count', 'date_fields'],
                'rows' => $pageRows,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => count($rows),
                    'last_page' => max((int) ceil(max(count($rows), 1) / $perPage), 1),
                    'from' => count($rows) > 0 ? $offset + 1 : null,
                    'to' => count($pageRows) > 0 ? $offset + count($pageRows) : null,
                ],
            ],
            'capabilities' => [
                'supports_date_filtering' => false,
                'supports_period_aggregation' => false,
                'supports_grouped_charts' => count($topRows) > 0,
            ],
        ];
    }

    private function buildResourceChart(
        DatabaseConnector $connector,
        string $resource,
        ?string $dateColumn,
        ?string $groupColumn,
        string $graphType,
        string $period,
        array $filters,
    ): array {
        if ($dateColumn && $period !== 'none') {
            $points = $connector->aggregateByDate($resource, $dateColumn, 'count', null, $filters, $period, 100);

            return [
                'type' => $graphType,
                'title' => 'Records grouped ' . $this->periodLabel($period),
                'labels' => array_column($points, 'label'),
                'series' => array_column($points, 'value'),
                'empty_message' => count($points) === 0 ? 'No records matched the selected date range.' : null,
                'meta' => [
                    'mode' => 'date',
                    'group_by' => $dateColumn,
                ],
            ];
        }

        if ($groupColumn) {
            $points = $connector->aggregateByGroup($resource, $groupColumn, 'count', null, $filters, 12);

            return [
                'type' => $graphType === 'table' ? 'bar' : $graphType,
                'title' => 'Records grouped by ' . $groupColumn,
                'labels' => array_column($points, 'label'),
                'series' => array_column($points, 'value'),
                'empty_message' => count($points) === 0 ? 'No grouped data was returned for the selected resource.' : null,
                'meta' => [
                    'mode' => 'group',
                    'group_by' => $groupColumn,
                ],
            ];
        }

        return [
            'type' => $graphType,
            'title' => 'No chart data available',
            'labels' => [],
            'series' => [],
            'empty_message' => 'This resource does not expose a suitable date or grouping field for charting.',
            'meta' => [
                'mode' => 'empty',
                'group_by' => null,
            ],
        ];
    }

    private function normalizeResource(mixed $value, array $resources): ?string
    {
        $resource = is_string($value) ? trim($value) : '';
        if ($resource === '') {
            return null;
        }

        if (!in_array($resource, $resources, true)) {
            throw new DatabaseConnectorException('The selected table or collection is not available on this connection.');
        }

        return $resource;
    }

    private function normalizeGraphType(string $graphType): string
    {
        return match (strtolower(trim($graphType))) {
            'table_report', 'table' => 'table',
            'bar_graph', 'bar' => 'bar',
            'pie_chart', 'pie' => 'pie',
            'line_graph', 'line' => 'line',
            default => 'table',
        };
    }

    private function normalizeChartMode(mixed $value): string
    {
        $mode = is_string($value) ? strtolower(trim($value)) : '';

        return match ($mode) {
            'date' => 'date',
            'group' => 'group',
            'resource_overview' => 'resource_overview',
            default => throw new DatabaseConnectorException('The selected chart cannot be drilled into.'),
        };
    }

    private function normalizePeriod(string $period): string
    {
        return match (strtolower(trim($period))) {
            'daily' => 'daily',
            'weekly' => 'weekly',
            'monthly' => 'monthly',
            'semi_annual', 'semiannual', 'semi-annual' => 'semiannual',
            'annually', 'annual' => 'annual',
            default => 'none',
        };
    }

    private function periodLabel(string $period): string
    {
        return match ($period) {
            'daily' => 'daily',
            'weekly' => 'weekly',
            'monthly' => 'monthly',
            'semiannual' => 'semi-annually',
            'annual' => 'annually',
            default => 'without a period',
        };
    }

    private function buildDateFilters(?string $dateColumn, array $input): array
    {
        if (!$dateColumn) {
            return [];
        }

        return array_filter([
            'date_column' => $dateColumn,
            'from' => $input['from'] ?? null,
            'to' => $input['to'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
    }

    private function mergeDateRanges(array $baseFilters, array $bucketFilters): array
    {
        $dateColumn = $bucketFilters['date_column'] ?? $baseFilters['date_column'] ?? null;
        if (!$dateColumn) {
            return $baseFilters;
        }

        $fromCandidates = array_values(array_filter([
            $baseFilters['from'] ?? null,
            $bucketFilters['from'] ?? null,
        ], fn($value) => $value !== null && $value !== ''));

        $toCandidates = array_values(array_filter([
            $baseFilters['to'] ?? null,
            $bucketFilters['to'] ?? null,
        ], fn($value) => $value !== null && $value !== ''));

        return array_filter([
            'date_column' => $dateColumn,
            'from' => $fromCandidates === [] ? null : max($fromCandidates),
            'to' => $toCandidates === [] ? null : min($toCandidates),
        ], fn($value) => $value !== null && $value !== '');
    }

    private function buildBucketDateFilters(string $dateColumn, string $bucketLabel, string $period): array
    {
        [$from, $to] = $this->bucketDateRange($bucketLabel, $period);

        return [
            'date_column' => $dateColumn,
            'from' => $from,
            'to' => $to,
        ];
    }

    private function bucketDateRange(string $bucketLabel, string $period): array
    {
        $label = trim($bucketLabel);

        try {
            return match ($period) {
                'daily' => $this->calendarRange(Carbon::parse($label)->startOfDay(), Carbon::parse($label)->endOfDay()),
                'weekly' => $this->weeklyBucketRange($label),
                'monthly' => $this->calendarRange(Carbon::parse($label)->startOfMonth(), Carbon::parse($label)->endOfMonth()),
                'semiannual' => $this->semiannualBucketRange($label),
                'annual' => $this->calendarRange(Carbon::parse($label)->startOfYear(), Carbon::parse($label)->endOfYear()),
                default => throw new DatabaseConnectorException('The selected report period does not support drill-down.'),
            };
        } catch (Throwable $exception) {
            throw new DatabaseConnectorException('Unable to determine the selected chart bucket range.');
        }
    }

    private function weeklyBucketRange(string $bucketLabel): array
    {
        if (preg_match('/^(?<year>\d{4})-W(?<week>\d{2})$/', $bucketLabel, $matches) === 1) {
            $start = Carbon::now()->setISODate((int) $matches['year'], (int) $matches['week'])->startOfDay();

            return $this->calendarRange($start, (clone $start)->endOfWeek(Carbon::SUNDAY));
        }

        $start = Carbon::parse($bucketLabel)->startOfWeek(Carbon::MONDAY);

        return $this->calendarRange($start, (clone $start)->endOfWeek(Carbon::SUNDAY));
    }

    private function semiannualBucketRange(string $bucketLabel): array
    {
        if (preg_match('/^(?<year>\d{4})-H(?<half>[12])$/', $bucketLabel, $matches) === 1) {
            $startMonth = (int) $matches['half'] === 1 ? 1 : 7;
            $start = Carbon::create((int) $matches['year'], $startMonth, 1)->startOfDay();

            return $this->calendarRange($start, (clone $start)->addMonths(5)->endOfMonth());
        }

        $start = Carbon::parse($bucketLabel)->startOfDay();

        return $this->calendarRange($start, (clone $start)->addMonths(5)->endOfMonth());
    }

    private function calendarRange(Carbon $from, Carbon $to): array
    {
        return [
            $from->toDateString(),
            $to->toDateTimeString(),
        ];
    }

    private function pickColumn(array $columns, mixed $preferred, callable $predicate): ?string
    {
        $preferredName = $this->pickColumnName($columns, $preferred);
        if ($preferredName !== null) {
            foreach ($columns as $column) {
                if (($column['name'] ?? null) === $preferredName && $predicate($column)) {
                    return $preferredName;
                }
            }
        }

        foreach ($columns as $column) {
            if ($predicate($column)) {
                return $column['name'] ?? null;
            }
        }

        return null;
    }

    private function pickColumnName(array $columns, mixed $preferred): ?string
    {
        if (!is_string($preferred) || trim($preferred) === '') {
            return null;
        }

        foreach ($columns as $column) {
            if (($column['name'] ?? null) === trim($preferred)) {
                return $column['name'];
            }
        }

        return null;
    }

    private function isDateColumn(array $column): bool
    {
        $name = strtolower((string) ($column['name'] ?? ''));
        $type = strtolower((string) ($column['type'] ?? ''));

        return preg_match('/date|time|timestamp/', $type) === 1
            || preg_match('/(^|_)(date|time|created_at|updated_at|deleted_at)$/', $name) === 1;
    }

    private function isGroupableColumn(array $column): bool
    {
        $name = strtolower((string) ($column['name'] ?? ''));
        $type = strtolower((string) ($column['type'] ?? ''));

        if (preg_match('/(^id$|_id$)/', $name) === 1) {
            return false;
        }

        if (preg_match('/status|type|category|source|role|level|state|priority/', $name) === 1) {
            return true;
        }

        return preg_match('/char|text|string|enum|bool|json|array|object|date|time|timestamp/', $type) === 1;
    }

    private function resolveTableColumns(array $rows, array $columns): array
    {
        if ($rows !== []) {
            return array_keys((array) $rows[0]);
        }

        return array_values(array_map(fn(array $column) => $column['name'], $columns));
    }
}
