<?php

namespace App\Services\Chatbot;

use App\Models\ConnectedDatabase;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorException;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatbotContextBuilder
{
    public function __construct(protected ChatbotSchemaTrainer $schemaTrainer)
    {
    }

    public function build(ConnectedDatabase $database, DatabaseConnector $connector, ?string $resource = null): array
    {
        $resources = $connector->listResources();
        sort($resources);

        $selectedResource = $this->normalizeResource($resource, $resources);

        if ($resources === []) {
            return [
                'database' => $database->publicMetadata(),
                'resource_type' => $connector->resourceType(),
                'selected_resource' => null,
                'available_resources' => [],
                'resource_profiles' => [],
                'training_profile' => $this->schemaTrainer->buildTrainingProfile([]),
                'overview' => [
                    'resource_count' => 0,
                    'profiled_resource_count' => 0,
                    'date_ready_resources' => 0,
                    'known_record_total' => 0,
                ],
                'sufficient_data' => false,
                'insufficiencies' => [
                    'The selected database does not expose any tables or collections that can be analyzed.',
                ],
                'summary' => 'No tables or collections are available for grounded answers on this connection.',
                'suggested_prompts' => [],
            ];
        }

        $profileLimit = max((int) config('chatbot.resource_context_limit', 20), 1);
        $profileResources = $selectedResource !== null ? [$selectedResource] : array_slice($resources, 0, $profileLimit);
        $profiles = [];
        $resourceWarnings = [];

        foreach ($profileResources as $resourceName) {
            try {
                $profiles[] = $this->buildResourceProfile(
                    connector: $connector,
                    resource: $resourceName,
                    includeRichData: false,
                );
            } catch (Throwable $exception) {
                if ($selectedResource !== null) {
                    throw $exception;
                }

                Log::warning('Skipping resource during chatbot whole-database context build.', [
                    'database_id' => $database->id,
                    'resource' => $resourceName,
                    'error' => $exception->getMessage(),
                ]);

                $resourceWarnings[] = sprintf(
                    'Skipped %s %s because its schema or record summary could not be prepared.',
                    $connector->resourceType(),
                    $resourceName
                );
            }
        }

        usort($profiles, function (array $left, array $right) {
            if (($left['record_count'] ?? 0) === ($right['record_count'] ?? 0)) {
                return strcmp((string) ($left['resource'] ?? ''), (string) ($right['resource'] ?? ''));
            }

            return (int) ($right['record_count'] ?? 0) <=> (int) ($left['record_count'] ?? 0);
        });

        $richResourceNames = $selectedResource !== null
            ? [$selectedResource]
            : array_values(array_map(
                fn(array $profile) => (string) ($profile['resource'] ?? ''),
                array_slice($profiles, 0, 3)
            ));

        foreach ($profiles as $index => $profile) {
            $resourceName = (string) ($profile['resource'] ?? '');
            if ($resourceName === '' || !in_array($resourceName, $richResourceNames, true)) {
                continue;
            }

            try {
                $profiles[$index] = $this->buildResourceProfile(
                    connector: $connector,
                    resource: $resourceName,
                    includeRichData: true,
                );
            } catch (Throwable $exception) {
                if ($selectedResource !== null) {
                    throw $exception;
                }

                Log::warning('Skipping rich chatbot resource profile enrichment.', [
                    'database_id' => $database->id,
                    'resource' => $resourceName,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $dateReadyResources = count(array_filter(
            $profiles,
            fn(array $profile) => !empty($profile['detected']['date_column'])
        ));

        $knownRecordTotal = array_sum(array_map(
            fn(array $profile) => (int) ($profile['record_count'] ?? 0),
            $profiles
        ));

        $summary = $selectedResource !== null
            ? $this->resourceSummary($selectedResource, $profiles[0] ?? [])
            : $this->databaseSummary($connector->resourceType(), $resources, $profiles, $dateReadyResources, $knownRecordTotal);

        $insufficiencies = $resourceWarnings;
        if ($selectedResource !== null && empty($profiles[0]['columns']) && (($profiles[0]['record_count'] ?? 0) === 0)) {
            $insufficiencies[] = 'The selected resource does not expose enough schema or row data for grounded answers yet.';
        }

        if ($selectedResource === null && $profiles === []) {
            $insufficiencies[] = 'I could not prepare any table or collection summaries for whole-database mode on this connection.';
        }

        return [
            'database' => $database->publicMetadata(),
            'resource_type' => $connector->resourceType(),
            'selected_resource' => $selectedResource,
            'available_resources' => $resources,
            'resource_profiles' => $profiles,
            'training_profile' => $this->schemaTrainer->buildTrainingProfile($profiles),
            'semantic_summary' => $selectedResource !== null
                ? ($profiles[0]['description'] ?? $summary)
                : $summary,
            'overview' => [
                'resource_count' => count($resources),
                'profiled_resource_count' => count($profiles),
                'date_ready_resources' => $dateReadyResources,
                'known_record_total' => $knownRecordTotal,
                'profiles_truncated' => count($resources) > count($profiles),
            ],
            'sufficient_data' => $profiles !== [],
            'insufficiencies' => $insufficiencies,
            'summary' => $summary,
            'suggested_prompts' => $this->suggestedPrompts($connector->resourceType(), $selectedResource, $profiles[0] ?? null),
        ];
    }

    public function normalizeResource(?string $resource, array $resources): ?string
    {
        $resource = is_string($resource) ? trim($resource) : '';

        if ($resource === '') {
            return null;
        }

        if (!in_array($resource, $resources, true)) {
            throw new DatabaseConnectorException('The selected table or collection is not available on this connection.');
        }

        return $resource;
    }

    public function buildResourceProfile(
        DatabaseConnector $connector,
        string $resource,
        bool $includeRichData = true,
    ): array {
        $schema = $connector->getSchema($resource)[0] ?? [
            'resource' => $resource,
            'columns' => [],
        ];
        $columns = $schema['columns'] ?? [];
        $recordCount = (int) $connector->countRecords($resource);
        $dateColumns = array_values(array_map(
            fn(array $column) => $column['name'],
            array_filter($columns, fn(array $column) => $this->isDateColumn($column))
        ));
        $groupColumns = array_values(array_map(
            fn(array $column) => $column['name'],
            array_filter($columns, fn(array $column) => $this->isGroupableColumn($column))
        ));

        $detectedDateColumn = $dateColumns[0] ?? null;
        $detectedGroupColumn = $groupColumns[0] ?? null;
        $sampleRows = [];
        $topGroups = [];
        $monthlyTrend = [];

        if ($includeRichData && $recordCount > 0) {
            $sampleRows = $connector->previewRows(
                $resource,
                [],
                max((int) config('chatbot.sample_rows_limit', 5), 1)
            );

            if ($detectedGroupColumn !== null) {
                $topGroups = $connector->aggregateByGroup(
                    $resource,
                    $detectedGroupColumn,
                    'count',
                    null,
                    [],
                    max((int) config('chatbot.top_group_limit', 6), 1)
                );
            }

            if ($detectedDateColumn !== null) {
                $monthlyTrend = $connector->aggregateByDate(
                    $resource,
                    $detectedDateColumn,
                    'count',
                    null,
                    [],
                    'monthly',
                    max((int) config('chatbot.trend_limit', 12), 1)
                );
            }
        }

        return [
            'resource' => $resource,
            'record_count' => $recordCount,
            'column_count' => count($columns),
            'columns' => $columns,
            'column_names' => array_values(array_map(fn(array $column) => $column['name'], $columns)),
            'date_columns' => $dateColumns,
            'group_columns' => $groupColumns,
            'detected' => [
                'date_column' => $detectedDateColumn,
                'group_column' => $detectedGroupColumn,
            ],
            'sample_rows' => $sampleRows,
            'top_groups' => $topGroups,
            'monthly_trend' => $monthlyTrend,
            'description' => $this->describeResourceProfile($resource, $recordCount, count($columns), $detectedDateColumn, $detectedGroupColumn),
            'semantic_terms' => $this->semanticTermsForProfile($resource, $columns, $topGroups),
        ];
    }

    private function resourceSummary(string $resource, array $profile): string
    {
        $recordCount = (int) ($profile['record_count'] ?? 0);
        $columnCount = (int) ($profile['column_count'] ?? 0);
        $dateColumn = $profile['detected']['date_column'] ?? null;
        $groupColumn = $profile['detected']['group_column'] ?? null;

        $parts = [
            sprintf('%s has %d records and %d columns available for grounding.', $resource, $recordCount, $columnCount),
        ];

        if ($dateColumn) {
            $parts[] = sprintf('Date analysis can use %s.', $dateColumn);
        }

        if ($groupColumn) {
            $parts[] = sprintf('Category-style grouping can use %s.', $groupColumn);
        }

        return implode(' ', $parts);
    }

    private function databaseSummary(
        string $resourceType,
        array $resources,
        array $profiles,
        int $dateReadyResources,
        int $knownRecordTotal,
    ): string {
        $topProfile = $profiles[0] ?? null;
        $parts = [
            sprintf(
                'The selected database exposes %d %s%s.',
                count($resources),
                $resourceType,
                count($resources) === 1 ? '' : 's'
            ),
            sprintf('Known record volume across the prepared context is %d.', $knownRecordTotal),
            sprintf('%d prepared %s support date-based trend analysis.', $dateReadyResources, $resourceType . ($dateReadyResources === 1 ? '' : 's')),
        ];

        if ($topProfile) {
            $parts[] = sprintf(
                'The largest prepared %s is %s with %d records.',
                $resourceType,
                $topProfile['resource'],
                (int) ($topProfile['record_count'] ?? 0)
            );
        }

        return implode(' ', $parts);
    }

    private function suggestedPrompts(string $resourceType, ?string $selectedResource, ?array $profile): array
    {
        $resourceLabel = $selectedResource ?? 'this ' . $resourceType;
        $prompts = [
            sprintf('How many records are in %s?', $resourceLabel),
            sprintf('Summarize %s.', $resourceLabel),
        ];

        if (!empty($profile['detected']['date_column'])) {
            $prompts[] = sprintf('Show monthly trend for %s.', $resourceLabel);
        }

        if (!empty($profile['detected']['group_column'])) {
            $prompts[] = sprintf('What are the top categories in %s?', $resourceLabel);
        }

        if ($selectedResource === null) {
            $prompts[] = sprintf('List the available %ss.', $resourceType);
        }

        return array_values(array_unique($prompts));
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

        if (preg_match('/status|type|category|source|role|level|state|priority|name/', $name) === 1) {
            return true;
        }

        return preg_match('/char|string|text|enum|bool|json|array|object/', $type) === 1;
    }

    private function describeResourceProfile(
        string $resource,
        int $recordCount,
        int $columnCount,
        ?string $dateColumn,
        ?string $groupColumn,
    ): string {
        $parts = [
            sprintf('%s has %d records and %d columns.', $resource, $recordCount, $columnCount),
        ];

        if ($dateColumn !== null) {
            $parts[] = sprintf('Time-based analysis can use %s.', $dateColumn);
        }

        if ($groupColumn !== null) {
            $parts[] = sprintf('Category-style grouping can use %s.', $groupColumn);
        }

        return implode(' ', $parts);
    }

    private function semanticTermsForProfile(string $resource, array $columns, array $topGroups): array
    {
        $terms = [$resource, str_replace(['_', '-'], ' ', $resource)];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $terms[] = $name;
            $terms[] = str_replace(['_', '-'], ' ', $name);
        }

        foreach ($topGroups as $point) {
            $label = (string) ($point['label'] ?? '');
            if ($label !== '') {
                $terms[] = $label;
            }
        }

        $terms = array_map(fn(string $term) => trim($term), $terms);

        return array_values(array_unique(array_filter($terms, fn(string $term) => $term !== '')));
    }
}
