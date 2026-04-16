<?php

namespace App\Services\Chatbot;

use App\Models\ConnectedDatabase;
use App\Models\User;
use App\Services\Chatbot\Contracts\ChatbotLanguageModel;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorManager;
use Illuminate\Support\Str;

class GroundedChatbotService
{
    public function __construct(
        protected DatabaseConnectorManager $manager,
        protected ChatbotContextBuilder $contextBuilder,
        protected ChatbotHistoryStore $historyStore,
        protected ChatbotLanguageModel $languageModel,
        protected OfficialUniversityKnowledgeService $universityKnowledge,
        protected ProjectionService $projectionService,
    ) {
    }

    public function prepareContext(User $user, int $databaseId, ?string $resource = null): array
    {
        $database = ConnectedDatabase::findOrFail($databaseId);
        $connector = $this->manager->for($database);
        $context = $this->contextBuilder->build($database, $connector, $resource);
        $stored = $this->historyStore->rememberContext($user, $database->id, $context['selected_resource'], $context);

        return [
            'context_id' => $stored['id'],
            'history' => $this->historyStore->getHistory($user, $database->id, $context['selected_resource']),
            ...$context,
        ];
    }

    public function ask(User $user, array $payload): array
    {
        $database = ConnectedDatabase::findOrFail((int) $payload['db_id']);
        $connector = $this->manager->for($database);
        $resource = $payload['resource'] ?? null;
        $contextPayload = $this->loadOrPrepareContext($user, $database, $connector, $resource, $payload['context_id'] ?? null);
        $context = $contextPayload['context'];
        $prompt = trim((string) $payload['prompt']);
        $analysis = $this->analyzePrompt($prompt, $database, $connector, $context);

        $analysis['answer'] = $this->languageModel->formatGroundedResponse($prompt, $context, $analysis)
            ?? $analysis['answer'];

        $userMessage = [
            'id' => (string) Str::uuid(),
            'role' => 'user',
            'content' => $prompt,
            'created_at' => now()->toIso8601String(),
        ];
        $assistantMessage = [
            'id' => (string) Str::uuid(),
            'role' => 'assistant',
            'content' => $analysis['answer'],
            'created_at' => now()->toIso8601String(),
            'intent' => $analysis['intent'],
            'grounded' => $analysis['grounded'],
            'insufficient_data' => $analysis['insufficient_data'],
            'facts' => $analysis['facts'],
            'warnings' => $analysis['warnings'],
            'suggestions' => $analysis['suggestions'],
            'table' => $analysis['table'],
            'chart' => $analysis['chart'],
        ];

        $history = $this->historyStore->appendTurn(
            $user,
            $database->id,
            $context['selected_resource'] ?? null,
            $userMessage,
            $assistantMessage,
        );

        return [
            'context_id' => $contextPayload['id'],
            'database' => $context['database'],
            'resource_type' => $context['resource_type'],
            'selected_resource' => $context['selected_resource'],
            'answer' => $analysis['answer'],
            'intent' => $analysis['intent'],
            'grounded' => $analysis['grounded'],
            'insufficient_data' => $analysis['insufficient_data'],
            'facts' => $analysis['facts'],
            'warnings' => $analysis['warnings'],
            'suggestions' => $analysis['suggestions'],
            'table' => $analysis['table'],
            'chart' => $analysis['chart'],
            'history' => $history,
        ];
    }

    public function history(User $user, int $databaseId, ?string $resource = null): array
    {
        return [
            'messages' => $this->historyStore->getHistory($user, $databaseId, $resource),
        ];
    }

    public function reset(User $user, int $databaseId, ?string $resource = null): array
    {
        $this->historyStore->reset($user, $databaseId, $resource);

        return ['ok' => true];
    }

    private function loadOrPrepareContext(
        User $user,
        ConnectedDatabase $database,
        DatabaseConnector $connector,
        ?string $resource,
        ?string $contextId,
    ): array {
        if (is_string($contextId) && trim($contextId) !== '') {
            $stored = $this->historyStore->getContext($user, trim($contextId));

            if (
                is_array($stored)
                && (int) ($stored['db_id'] ?? 0) === $database->id
                && (($stored['resource'] ?? null) === ($resource ?: null) || $resource === null)
            ) {
                return $stored;
            }
        }

        $context = $this->contextBuilder->build($database, $connector, $resource);

        return $this->historyStore->rememberContext($user, $database->id, $context['selected_resource'], $context);
    }

    private function analyzePrompt(
        string $prompt,
        ConnectedDatabase $database,
        DatabaseConnector $connector,
        array $context,
    ): array {
        $externalKnowledge = $this->universityKnowledge->search($prompt);
        $intent = $this->detectIntent($prompt, ($externalKnowledge['matches'] ?? []) !== []);
        $resources = $context['available_resources'] ?? [];
        $resource = $this->resolveTargetResource($prompt, $context, $resources, $intent, $connector);
        if ($intent === 'projection') {
            $resource = $this->resolveProjectionResource($prompt, $context, $resource);
        }

        return match ($intent) {
            'projection' => $this->answerProjection($connector, $context, $resource, $prompt, $externalKnowledge),
            'external_knowledge' => $this->answerExternalKnowledge($context, $prompt, $externalKnowledge),
            'list_resources' => $this->answerListResources($context),
            'schema' => $this->answerSchema($connector, $context, $resource),
            'count' => $this->answerCount($connector, $context, $resource, $prompt),
            'trend' => $this->answerTrend($connector, $context, $resource),
            'top_categories' => $this->answerTopCategories($connector, $context, $resource, $prompt),
            'preview' => $this->answerPreview($connector, $context, $resource),
            'summary' => $this->answerSummary($connector, $context, $resource),
            default => $this->answerUnsupported($database, $context, $resource),
        };
    }

    private function detectIntent(string $prompt, bool $hasExternalKnowledge = false): string
    {
        $message = Str::lower($prompt);

        if (preg_match('/\b(projection|project|forecast|predict|prediction|estimate|estimated|expected|expectation|scenario|projected)\b/', $message) === 1) {
            return 'projection';
        }

        if (preg_match('/\b(table|tables|collection|collections|resources?)\b/', $message) === 1
            && preg_match('/\b(list|show|available|what)\b/', $message) === 1) {
            return 'list_resources';
        }

        if (preg_match('/\b(schema|columns?|fields?|structure)\b/', $message) === 1) {
            return 'schema';
        }

        if (preg_match('/\b(how many|count|number of records|record count|row count|total records)\b/', $message) === 1) {
            return 'count';
        }

        if (preg_match('/\b(trend|over time|monthly|weekly|daily|annually|annual|yearly)\b/', $message) === 1) {
            return 'trend';
        }

        if (preg_match('/\b(top|most common|categories|category|breakdown|distribution|statuses|types)\b/', $message) === 1) {
            return 'top_categories';
        }

        if (preg_match('/\b(show|preview|sample|example|rows|records)\b/', $message) === 1) {
            return 'preview';
        }

        if (preg_match('/\b(summary|summarize|overview|describe|insight|insights)\b/', $message) === 1) {
            return 'summary';
        }

        if ($hasExternalKnowledge && $this->universityKnowledge->shouldAnswerDirectly($prompt)) {
            return 'external_knowledge';
        }

        return 'unsupported';
    }

    private function resolveTargetResource(
        string $prompt,
        array $context,
        array $resources,
        string $intent,
        DatabaseConnector $connector,
    ): ?string
    {
        $selectedResource = $context['selected_resource'] ?? null;
        $explicitResource = $this->matchResourceFromPrompt($prompt, $resources);

        if ($explicitResource !== null) {
            return $explicitResource;
        }

        if ($selectedResource !== null) {
            return $selectedResource;
        }

        if (count($resources) === 1) {
            return $resources[0];
        }

        $inferredResource = $this->inferResourceFromProfiles($prompt, $context['resource_profiles'] ?? []);
        if ($inferredResource !== null) {
            return $inferredResource;
        }

        $schemaInferredResource = $this->inferResourceFromSchemas($prompt, $resources, $connector);
        if ($schemaInferredResource !== null) {
            return $schemaInferredResource;
        }

        $lazyInferredResource = $this->inferResourceFromGroupValues($prompt, $context['resource_profiles'] ?? [], $connector);
        if ($lazyInferredResource !== null) {
            return $lazyInferredResource;
        }

        if (in_array($intent, ['list_resources', 'summary'], true)) {
            return null;
        }

        return null;
    }

    private function answerProjection(
        DatabaseConnector $connector,
        array $context,
        ?string $resource,
        string $prompt,
        array $externalKnowledge,
    ): array {
        if ($resource === null) {
            return [
                'intent' => 'projection',
                'grounded' => false,
                'insufficient_data' => true,
                'answer' => 'I could not determine which database resource should be used for this projection. Mention a traffic, vehicle, student, or other time-based table, or let me use a clearer event or metric name.',
                'facts' => array_slice((array) ($externalKnowledge['facts'] ?? []), 0, 2),
                'warnings' => array_values(array_unique(array_merge(
                    ['Projection requires a grounded resource with time-based data from the selected database.'],
                    (array) ($externalKnowledge['warnings'] ?? [])
                ))),
                'suggestions' => [
                    'Show monthly trend for vehicle_movements.',
                    'How many records are in vehicles?',
                    'List the official UP sources used for this answer.',
                ],
                'table' => null,
                'chart' => null,
            ];
        }

        $profile = $this->contextBuilder->buildResourceProfile($connector, $resource, false);
        $dateColumn = $this->resolveDateColumn($profile);

        if ($dateColumn === null) {
            return [
                'intent' => 'projection',
                'grounded' => false,
                'insufficient_data' => true,
                'answer' => sprintf('I found %s, but I could not identify a reliable date column for projection on that resource.', $resource),
                'facts' => array_merge([
                    sprintf('Projection resource: %s', $resource),
                ], array_slice((array) ($externalKnowledge['facts'] ?? []), 0, 2)),
                'warnings' => array_values(array_unique(array_merge(
                    ['Projection needs a grounded date or timestamp column.'],
                    (array) ($externalKnowledge['warnings'] ?? [])
                ))),
                'suggestions' => [
                    sprintf('Show the schema for %s.', $resource),
                    sprintf('Preview %s.', $resource),
                ],
                'table' => null,
                'chart' => null,
            ];
        }

        $projectionKnowledge = [
            'facts' => (array) ($externalKnowledge['facts'] ?? []),
            'warnings' => (array) ($externalKnowledge['warnings'] ?? []),
            'event_related' => ($externalKnowledge['matches'] ?? []) !== [],
            'major_event' => $this->universityKnowledge->mentionsMajorCampusEvent($prompt, $externalKnowledge),
        ];

        return $this->projectionService->buildProjection(
            $connector,
            $context,
            $resource,
            $dateColumn,
            $prompt,
            $projectionKnowledge,
        );
    }

    private function answerExternalKnowledge(array $context, string $prompt, array $externalKnowledge): array
    {
        $summary = $this->universityKnowledge->summarizeForAnswer($prompt, $externalKnowledge);

        return [
            'intent' => 'external_knowledge',
            'grounded' => $summary !== null,
            'insufficient_data' => $summary === null,
            'answer' => $summary ?? 'I could not find a configured official UP source for that question yet.',
            'facts' => array_slice((array) ($externalKnowledge['facts'] ?? []), 0, 3),
            'warnings' => array_values(array_unique(array_merge(
                $summary === null ? ['No matching official UP reference was found in the configured knowledge base.'] : [],
                (array) ($externalKnowledge['warnings'] ?? [])
            ))),
            'suggestions' => array_values(array_unique(array_filter([
                'List the official UP sources used for this answer.',
                ($context['suggested_prompts'][0] ?? null),
                'Summarize the selected database.',
            ]))),
            'table' => null,
            'chart' => null,
        ];
    }

    private function answerListResources(array $context): array
    {
        $resources = $context['available_resources'] ?? [];
        $resourceType = $context['resource_type'] ?? 'resource';
        $label = $resourceType . (count($resources) === 1 ? '' : 's');

        return [
            'intent' => 'list_resources',
            'grounded' => true,
            'insufficient_data' => $resources === [],
            'answer' => $resources === []
                ? sprintf('I could not find any %s on the selected database.', $label)
                : sprintf('I found %d available %s: %s.', count($resources), $label, implode(', ', $resources)),
            'facts' => array_map(
                fn(string $resource) => sprintf('%s available: %s', ucfirst($resourceType), $resource),
                array_slice($resources, 0, 12)
            ),
            'warnings' => [],
            'suggestions' => $context['suggested_prompts'] ?? [],
            'table' => [
                'columns' => [$resourceType],
                'rows' => array_map(fn(string $resource) => [$resourceType => $resource], array_slice($resources, 0, 25)),
            ],
            'chart' => null,
        ];
    }

    private function answerSchema(DatabaseConnector $connector, array $context, ?string $resource): array
    {
        if ($resource === null) {
            return [
                'intent' => 'schema',
                'grounded' => false,
                'insufficient_data' => true,
                'answer' => sprintf(
                    'Ask about a specific %s name first if you want column-level schema details. I can still list the available %ss for the current database.',
                    $context['resource_type'] ?? 'resource',
                    $context['resource_type'] ?? 'resource',
                ),
                'facts' => [],
                'warnings' => [],
                'suggestions' => [
                    sprintf('List the available %ss.', $context['resource_type'] ?? 'resource'),
                ],
                'table' => null,
                'chart' => null,
            ];
        }

        $profile = $this->contextBuilder->buildResourceProfile($connector, $resource, false);
        $columnRows = array_map(fn(array $column) => [
            'name' => $column['name'] ?? null,
            'type' => $column['type'] ?? null,
        ], $profile['columns'] ?? []);

        return [
            'intent' => 'schema',
            'grounded' => true,
            'insufficient_data' => $columnRows === [],
            'answer' => $columnRows === []
                ? sprintf('I could not detect any columns for %s.', $resource)
                : sprintf('I found %d columns on %s. The main fields are %s.', count($columnRows), $resource, implode(', ', array_slice($profile['column_names'] ?? [], 0, 8))),
            'facts' => [
                sprintf('Resource: %s', $resource),
                sprintf('Column count: %d', count($columnRows)),
            ],
            'warnings' => [],
            'suggestions' => [
                sprintf('How many records are in %s?', $resource),
                sprintf('Summarize %s.', $resource),
            ],
            'table' => [
                'columns' => ['name', 'type'],
                'rows' => array_slice($columnRows, 0, 50),
            ],
            'chart' => null,
        ];
    }

    private function answerCount(DatabaseConnector $connector, array $context, ?string $resource, string $prompt): array
    {
        if ($resource === null) {
            if ($this->isWholeDatabaseRequest($prompt)) {
                $counts = [];
                foreach ($context['available_resources'] ?? [] as $resourceName) {
                    $counts[] = [
                        'resource' => $resourceName,
                        'count' => (int) $connector->countRecords($resourceName),
                    ];
                }

                $total = array_sum(array_column($counts, 'count'));

                return [
                    'intent' => 'count',
                    'grounded' => true,
                    'insufficient_data' => false,
                    'answer' => sprintf(
                        'Across the selected database, I counted %d records over %d %s.',
                        $total,
                        count($counts),
                        ($context['resource_type'] ?? 'resource') . (count($counts) === 1 ? '' : 's')
                    ),
                    'facts' => [
                        sprintf('Total records counted across prepared resources: %d', $total),
                    ],
                    'warnings' => [],
                    'suggestions' => $context['suggested_prompts'] ?? [],
                    'table' => [
                        'columns' => ['resource', 'count'],
                        'rows' => array_slice($counts, 0, 25),
                    ],
                    'chart' => [
                        'type' => 'bar',
                        'title' => 'Record counts by resource',
                        'labels' => array_column(array_slice($counts, 0, 10), 'resource'),
                        'series' => array_column(array_slice($counts, 0, 10), 'count'),
                    ],
                ];
            }

            return $this->resourceSelectionRequired($context, 'I could not determine which resource to count for that question. Mention a table, collection, or a distinctive field or category value from the selected database.');
        }

        $profile = $this->contextBuilder->buildResourceProfile($connector, $resource, false);
        $groupResolution = $this->findBestGroupValueMatch($connector, $resource, $profile, $prompt);
        $groupColumn = $groupResolution['column'] ?? $this->resolveGroupColumn($prompt, $profile);
        $groupMatch = $groupResolution['match'] ?? null;

        if ($groupMatch !== null) {
            return [
                'intent' => 'count',
                'grounded' => true,
                'insufficient_data' => false,
                'answer' => sprintf(
                    'I counted %s records in %s where %s is %s.',
                    number_format((float) $groupMatch['value'], 0),
                    $resource,
                    $groupColumn,
                    $groupMatch['label']
                ),
                'facts' => [
                    sprintf('Resource: %s', $resource),
                    sprintf('Grouping column used: %s', $groupColumn),
                    sprintf('Matched value: %s', $groupMatch['label']),
                    sprintf('Record count: %s', number_format((float) $groupMatch['value'], 0)),
                ],
                'warnings' => [],
                'suggestions' => [
                    sprintf('What are the top categories in %s?', $resource),
                    sprintf('Summarize %s.', $resource),
                ],
                'table' => [
                    'columns' => ['resource', 'group_column', 'group_value', 'record_count'],
                    'rows' => [[
                        'resource' => $resource,
                        'group_column' => $groupColumn,
                        'group_value' => $groupMatch['label'],
                        'record_count' => $groupMatch['value'],
                    ]],
                ],
                'chart' => null,
            ];
        }

        $count = (int) $connector->countRecords($resource);

        return [
            'intent' => 'count',
            'grounded' => true,
            'insufficient_data' => false,
            'answer' => sprintf('I counted %d records in %s.', $count, $resource),
            'facts' => [
                sprintf('Resource: %s', $resource),
                sprintf('Record count: %d', $count),
            ],
            'warnings' => [],
            'suggestions' => [
                sprintf('Show monthly trend for %s.', $resource),
                sprintf('Summarize %s.', $resource),
            ],
            'table' => [
                'columns' => ['resource', 'record_count'],
                'rows' => [
                    ['resource' => $resource, 'record_count' => $count],
                ],
            ],
            'chart' => null,
        ];
    }

    private function answerTrend(DatabaseConnector $connector, array $context, ?string $resource): array
    {
        if ($resource === null) {
            return $this->resourceSelectionRequired($context, 'I could not determine which resource should be used for a time trend. Mention a table, collection, or a distinctive date-related field from the selected database.');
        }

        $profile = $this->contextBuilder->buildResourceProfile($connector, $resource, false);
        $dateColumn = $this->resolveDateColumn($profile);

        if ($dateColumn === null) {
            return [
                'intent' => 'trend',
                'grounded' => false,
                'insufficient_data' => true,
                'answer' => sprintf('I could not find a date-like column on %s, so I cannot build a grounded trend from it.', $resource),
                'facts' => [
                    sprintf('Resource: %s', $resource),
                    'Detected date column: none',
                ],
                'warnings' => [],
                'suggestions' => [
                    sprintf('Show the schema for %s.', $resource),
                    sprintf('Summarize %s.', $resource),
                ],
                'table' => null,
                'chart' => null,
            ];
        }

        $points = $connector->aggregateByDate(
            $resource,
            $dateColumn,
            'count',
            null,
            [],
            'monthly',
            max((int) config('chatbot.trend_limit', 12), 1)
        );

        if ($points === []) {
            return [
                'intent' => 'trend',
                'grounded' => false,
                'insufficient_data' => true,
                'answer' => sprintf('I tried to group %s by %s monthly, but no trend data was returned.', $resource, $dateColumn),
                'facts' => [
                    sprintf('Resource: %s', $resource),
                    sprintf('Date column used: %s', $dateColumn),
                ],
                'warnings' => [],
                'suggestions' => [
                    sprintf('How many records are in %s?', $resource),
                    sprintf('Preview %s.', $resource),
                ],
                'table' => null,
                'chart' => null,
            ];
        }

        $topBucket = collect($points)->sortByDesc('value')->first();

        return [
            'intent' => 'trend',
            'grounded' => true,
            'insufficient_data' => false,
            'answer' => sprintf(
                'I grouped %s by %s monthly. The highest period in the prepared result is %s with %s records.',
                $resource,
                $dateColumn,
                $topBucket['label'] ?? 'n/a',
                number_format((float) ($topBucket['value'] ?? 0), 0)
            ),
            'facts' => [
                sprintf('Resource: %s', $resource),
                sprintf('Date column used: %s', $dateColumn),
                sprintf('Trend points returned: %d', count($points)),
            ],
            'warnings' => [],
            'suggestions' => [
                sprintf('What are the top categories in %s?', $resource),
                sprintf('Summarize %s.', $resource),
            ],
            'table' => [
                'columns' => ['period', 'value'],
                'rows' => array_map(fn(array $point) => [
                    'period' => $point['label'],
                    'value' => $point['value'],
                ], $points),
            ],
            'chart' => [
                'type' => 'line',
                'title' => sprintf('Monthly trend for %s', $resource),
                'labels' => array_column($points, 'label'),
                'series' => array_column($points, 'value'),
            ],
        ];
    }

    private function answerTopCategories(
        DatabaseConnector $connector,
        array $context,
        ?string $resource,
        string $prompt,
    ): array {
        if ($resource === null) {
            return $this->resourceSelectionRequired($context, 'I could not determine which resource should be grouped for that question. Mention a table, collection, or a distinctive category field from the selected database.');
        }

        $profile = $this->contextBuilder->buildResourceProfile($connector, $resource, false);
        $groupColumn = $this->resolveGroupColumn($prompt, $profile);

        if ($groupColumn === null) {
            return [
                'intent' => 'top_categories',
                'grounded' => false,
                'insufficient_data' => true,
                'answer' => sprintf('I could not find a category-style column on %s, so I cannot produce a grounded top-category breakdown.', $resource),
                'facts' => [
                    sprintf('Resource: %s', $resource),
                    'Detected grouping column: none',
                ],
                'warnings' => [],
                'suggestions' => [
                    sprintf('Show the schema for %s.', $resource),
                    sprintf('Preview %s.', $resource),
                ],
                'table' => null,
                'chart' => null,
            ];
        }

        $points = $connector->aggregateByGroup(
            $resource,
            $groupColumn,
            'count',
            null,
            [],
            max((int) config('chatbot.top_group_limit', 6), 1)
        );

        if ($points === []) {
            return [
                'intent' => 'top_categories',
                'grounded' => false,
                'insufficient_data' => true,
                'answer' => sprintf('I used %s as the grouping field for %s, but no grouped results were returned.', $groupColumn, $resource),
                'facts' => [
                    sprintf('Resource: %s', $resource),
                    sprintf('Grouping column used: %s', $groupColumn),
                ],
                'warnings' => [],
                'suggestions' => [
                    sprintf('How many records are in %s?', $resource),
                    sprintf('Preview %s.', $resource),
                ],
                'table' => null,
                'chart' => null,
            ];
        }

        $highlights = array_map(
            fn(array $point) => sprintf('%s (%s)', $point['label'], number_format((float) $point['value'], 0)),
            array_slice($points, 0, 3)
        );

        return [
            'intent' => 'top_categories',
            'grounded' => true,
            'insufficient_data' => false,
            'answer' => sprintf(
                'I grouped %s by %s. The top categories are %s.',
                $resource,
                $groupColumn,
                implode(', ', $highlights)
            ),
            'facts' => [
                sprintf('Resource: %s', $resource),
                sprintf('Grouping column used: %s', $groupColumn),
                sprintf('Groups returned: %d', count($points)),
            ],
            'warnings' => [],
            'suggestions' => [
                sprintf('Show monthly trend for %s.', $resource),
                sprintf('Summarize %s.', $resource),
            ],
            'table' => [
                'columns' => ['label', 'value'],
                'rows' => $points,
            ],
            'chart' => [
                'type' => 'bar',
                'title' => sprintf('Top groups in %s', $resource),
                'labels' => array_column($points, 'label'),
                'series' => array_column($points, 'value'),
            ],
        ];
    }

    private function answerPreview(DatabaseConnector $connector, array $context, ?string $resource): array
    {
        if ($resource === null) {
            return $this->resourceSelectionRequired($context, 'I could not determine which resource to preview. Mention a table, collection, or a distinctive field from the selected database.');
        }

        $rows = $connector->previewRows($resource, [], max((int) config('chatbot.sample_rows_limit', 5), 1));
        $columns = $rows !== [] ? array_keys((array) $rows[0]) : [];

        return [
            'intent' => 'preview',
            'grounded' => true,
            'insufficient_data' => $rows === [],
            'answer' => $rows === []
                ? sprintf('I did not get any rows back from %s.', $resource)
                : sprintf('Here is a grounded preview of %d row%s from %s.', count($rows), count($rows) === 1 ? '' : 's', $resource),
            'facts' => [
                sprintf('Resource: %s', $resource),
                sprintf('Preview rows returned: %d', count($rows)),
            ],
            'warnings' => [],
            'suggestions' => [
                sprintf('How many records are in %s?', $resource),
                sprintf('Summarize %s.', $resource),
            ],
            'table' => [
                'columns' => $columns,
                'rows' => $rows,
            ],
            'chart' => null,
        ];
    }

    private function answerSummary(DatabaseConnector $connector, array $context, ?string $resource): array
    {
        if ($resource === null) {
            $overview = $context['overview'] ?? [];
            $profiles = $context['resource_profiles'] ?? [];
            $topProfile = $profiles[0] ?? null;

            return [
                'intent' => 'summary',
                'grounded' => true,
                'insufficient_data' => false,
                'answer' => (string) ($context['summary'] ?? 'I prepared a whole-database summary from the selected connection.'),
                'facts' => array_values(array_filter([
                    sprintf('Prepared resources: %d', (int) ($overview['profiled_resource_count'] ?? 0)),
                    sprintf('Known record total: %d', (int) ($overview['known_record_total'] ?? 0)),
                    sprintf('Date-ready resources: %d', (int) ($overview['date_ready_resources'] ?? 0)),
                    $topProfile ? sprintf('Largest prepared resource: %s (%d)', $topProfile['resource'], (int) ($topProfile['record_count'] ?? 0)) : null,
                ])),
                'warnings' => !empty($overview['profiles_truncated'])
                    ? ['Only the first batch of resources was deeply profiled for context. Ask about a specific table or collection name for deeper grounding.']
                    : [],
                'suggestions' => $context['suggested_prompts'] ?? [],
                'table' => [
                    'columns' => ['resource', 'record_count', 'column_count', 'date_column', 'group_column'],
                    'rows' => array_map(fn(array $profile) => [
                        'resource' => $profile['resource'],
                        'record_count' => $profile['record_count'],
                        'column_count' => $profile['column_count'],
                        'date_column' => $profile['detected']['date_column'],
                        'group_column' => $profile['detected']['group_column'],
                    ], array_slice($profiles, 0, 20)),
                ],
                'chart' => $profiles === []
                    ? null
                    : [
                        'type' => 'bar',
                        'title' => 'Prepared resource sizes',
                        'labels' => array_column(array_slice($profiles, 0, 10), 'resource'),
                        'series' => array_column(array_slice($profiles, 0, 10), 'record_count'),
                    ],
            ];
        }

        $profile = $this->contextBuilder->buildResourceProfile($connector, $resource, true);
        $highlights = [];

        if (!empty($profile['top_groups'])) {
            $highlights[] = 'Top groups: ' . implode(', ', array_map(
                fn(array $point) => sprintf('%s (%s)', $point['label'], number_format((float) $point['value'], 0)),
                array_slice($profile['top_groups'], 0, 3)
            ));
        }

        if (!empty($profile['monthly_trend'])) {
            $latestTrend = $profile['monthly_trend'][array_key_last($profile['monthly_trend'])] ?? null;
            if ($latestTrend) {
                $highlights[] = sprintf('Latest prepared month: %s (%s records)', $latestTrend['label'], number_format((float) $latestTrend['value'], 0));
            }
        }

        return [
            'intent' => 'summary',
            'grounded' => true,
            'insufficient_data' => false,
            'answer' => trim(implode(' ', array_filter([
                sprintf(
                    '%s has %d records and %d columns in the current grounded context.',
                    $resource,
                    (int) ($profile['record_count'] ?? 0),
                    (int) ($profile['column_count'] ?? 0)
                ),
                !empty($profile['detected']['date_column']) ? sprintf('Trend analysis can use %s.', $profile['detected']['date_column']) : null,
                !empty($profile['detected']['group_column']) ? sprintf('Grouped analysis can use %s.', $profile['detected']['group_column']) : null,
                $highlights !== [] ? implode(' ', $highlights) . '.' : null,
            ]))),
            'facts' => array_values(array_filter([
                sprintf('Resource: %s', $resource),
                sprintf('Record count: %d', (int) ($profile['record_count'] ?? 0)),
                sprintf('Column count: %d', (int) ($profile['column_count'] ?? 0)),
                !empty($profile['detected']['date_column']) ? sprintf('Detected date column: %s', $profile['detected']['date_column']) : null,
                !empty($profile['detected']['group_column']) ? sprintf('Detected grouping column: %s', $profile['detected']['group_column']) : null,
            ])),
            'warnings' => [],
            'suggestions' => [
                sprintf('How many records are in %s?', $resource),
                sprintf('Show monthly trend for %s.', $resource),
                sprintf('What are the top categories in %s?', $resource),
            ],
            'table' => [
                'columns' => $profile['sample_rows'] !== [] ? array_keys((array) $profile['sample_rows'][0]) : [],
                'rows' => $profile['sample_rows'],
            ],
            'chart' => !empty($profile['monthly_trend'])
                ? [
                    'type' => 'line',
                    'title' => sprintf('Monthly trend for %s', $resource),
                    'labels' => array_column($profile['monthly_trend'], 'label'),
                    'series' => array_column($profile['monthly_trend'], 'value'),
                ]
                : null,
        ];
    }

    private function answerUnsupported(ConnectedDatabase $database, array $context, ?string $resource): array
    {
        $resourceType = $context['resource_type'] ?? $database->resourceLabel();
        $resourceHint = $resource
            ? sprintf('I can keep working with %s.', $resource)
            : sprintf('Mention a %s name for row-level answers, or ask for a database summary.', $resourceType);

        return [
            'intent' => 'unsupported',
            'grounded' => false,
            'insufficient_data' => true,
            'answer' => 'I can answer grounded questions about schema, record counts, top categories, sample rows, and date-based trends. ' . $resourceHint,
            'facts' => [],
            'warnings' => ['This request did not map cleanly to a safe grounded database operation.'],
            'suggestions' => $context['suggested_prompts'] ?? [],
            'table' => null,
            'chart' => null,
        ];
    }

    private function resourceSelectionRequired(array $context, string $message): array
    {
        $resourceType = $context['resource_type'] ?? 'resource';

        return [
            'intent' => 'needs_resource',
            'grounded' => false,
            'insufficient_data' => true,
            'answer' => $message,
            'facts' => [],
            'warnings' => [],
            'suggestions' => [
                sprintf('List the available %ss.', $resourceType),
                sprintf('Mention a %s and ask for a summary.', $resourceType),
            ],
            'table' => null,
            'chart' => null,
        ];
    }

    private function matchResourceFromPrompt(string $prompt, array $resources): ?string
    {
        $normalizedPrompt = $this->normalizeSearchText($prompt);
        usort($resources, fn(string $left, string $right) => strlen($right) <=> strlen($left));

        foreach ($resources as $resource) {
            $variants = array_unique([
                $this->normalizeSearchText($resource),
                $this->normalizeSearchText(str_replace(['_', '-'], ' ', $resource)),
            ]);

            foreach ($variants as $variant) {
                if ($variant !== '' && preg_match('/\b' . preg_quote($variant, '/') . '\b/', $normalizedPrompt) === 1) {
                    return $resource;
                }
            }
        }

        return null;
    }

    private function resolveDateColumn(array $profile): ?string
    {
        $dateColumns = array_values(array_filter(array_map('strval', (array) ($profile['date_columns'] ?? []))));
        if ($dateColumns === []) {
            return $profile['detected']['date_column'] ?? null;
        }

        usort($dateColumns, fn(string $left, string $right) => $this->dateColumnScore($right) <=> $this->dateColumnScore($left));

        return $dateColumns[0];
    }

    private function resolveProjectionResource(string $prompt, array $context, ?string $resource): ?string
    {
        $profiles = $context['resource_profiles'] ?? [];
        $normalizedPrompt = $this->normalizeSearchText($prompt);
        $trafficProjectionPrompt = preg_match('/\b(traffic|congestion|parking|vehicle|movement)\b/', $normalizedPrompt) === 1;

        if ($resource !== null) {
            $normalizedResource = $this->normalizeSearchText($resource);
            if ($normalizedResource !== '' && preg_match('/\b' . preg_quote($normalizedResource, '/') . '\b/', $normalizedPrompt) === 1) {
                foreach ($profiles as $profile) {
                    if (($profile['resource'] ?? null) === $resource && !empty($profile['detected']['date_column'])) {
                        return $resource;
                    }
                }
            }
        }

        if ($trafficProjectionPrompt) {
            $movementCandidates = array_values(array_filter($profiles, function (array $profile) {
                $resourceName = $this->normalizeSearchText((string) ($profile['resource'] ?? ''));
                $columnNames = array_values(array_filter(array_map(fn($column) => $this->normalizeSearchText((string) $column), (array) ($profile['column_names'] ?? []))));

                return !empty($profile['detected']['date_column'])
                    && (
                        preg_match('/\b(movement|movements|log|logs)\b/', $resourceName) === 1
                        || in_array('timestamp', $columnNames, true)
                    );
            }));

            if ($movementCandidates !== []) {
                usort($movementCandidates, fn(array $left, array $right) => $this->projectionResourcePreferenceScore($normalizedPrompt, $right) <=> $this->projectionResourcePreferenceScore($normalizedPrompt, $left));

                return (string) ($movementCandidates[0]['resource'] ?? $resource);
            }
        }

        $bestResource = $resource;
        $bestScore = -1;

        foreach ($profiles as $profile) {
            $resourceName = (string) ($profile['resource'] ?? '');
            if ($resourceName === '') {
                continue;
            }

            $score = 0;
            $score += $this->scoreTextAgainstPrompt($normalizedPrompt, $resourceName, 6);

            foreach ((array) ($profile['column_names'] ?? []) as $columnName) {
                $score += $this->scoreTextAgainstPrompt($normalizedPrompt, (string) $columnName, 5);
            }

            if (!empty($profile['detected']['date_column'])) {
                $score += 8;
            }

            if (preg_match('/\b(traffic|congestion|parking|vehicle|movement|violation|commencement|graduation)\b/', $normalizedPrompt) === 1) {
                $score += $this->semanticAssociationScore($normalizedPrompt, $this->normalizeSearchText($resourceName));
            }

            $score += $this->projectionResourcePreferenceScore($normalizedPrompt, $profile);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestResource = $resourceName;
            }
        }

        return $bestScore > 0 ? $bestResource : $resource;
    }

    private function resolveGroupColumn(string $prompt, array $profile): ?string
    {
        $columns = $profile['columns'] ?? [];
        $matched = $this->matchColumnFromPrompt($prompt, $columns);
        if ($matched !== null) {
            return $matched;
        }

        $groupColumns = $this->candidateGroupColumns($profile);
        if ($groupColumns !== []) {
            usort($groupColumns, fn(string $left, string $right) => $this->scoreGroupColumnForPrompt($prompt, $right) <=> $this->scoreGroupColumnForPrompt($prompt, $left));

            return $groupColumns[0];
        }

        return $profile['detected']['group_column'] ?? null;
    }

    private function matchColumnFromPrompt(string $prompt, array $columns): ?string
    {
        $normalizedPrompt = $this->normalizeSearchText($prompt);
        $bestColumn = null;
        $bestScore = 0;

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $variants = array_unique([
                $this->normalizeSearchText($name),
                $this->normalizeSearchText(str_replace(['_', '-'], ' ', $name)),
            ]);

            foreach ($variants as $variant) {
                if ($variant === '') {
                    continue;
                }

                $score = preg_match('/\b' . preg_quote($variant, '/') . '\b/', $normalizedPrompt) === 1
                    ? 100
                    : $this->scoreTermOverlap($normalizedPrompt, $variant) + $this->columnSemanticScore($name);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestColumn = $name;
                }
            }
        }

        return $bestScore > 0 ? $bestColumn : null;
    }

    private function isWholeDatabaseRequest(string $prompt): bool
    {
        $prompt = Str::lower($prompt);

        return preg_match('/\b(database|entire database|whole database|all tables|all collections|all resources)\b/', $prompt) === 1;
    }

    private function inferResourceFromProfiles(string $prompt, array $profiles): ?string
    {
        $normalizedPrompt = $this->normalizeSearchText($prompt);
        $bestResource = null;
        $bestScore = 0;

        foreach ($profiles as $profile) {
            $resource = (string) ($profile['resource'] ?? '');
            if ($resource === '') {
                continue;
            }

            $score = 0;

            $score += $this->scoreTextAgainstPrompt($normalizedPrompt, $resource, 5);

            foreach (($profile['column_names'] ?? []) as $columnName) {
                $score += $this->scoreTextAgainstPrompt($normalizedPrompt, (string) $columnName, 4);
            }

            foreach (($profile['top_groups'] ?? []) as $point) {
                $score += $this->scoreTextAgainstPrompt($normalizedPrompt, (string) ($point['label'] ?? ''), 6);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestResource = $resource;
            }
        }

        return $bestScore > 0 ? $bestResource : null;
    }

    private function inferResourceFromSchemas(
        string $prompt,
        array $resources,
        DatabaseConnector $connector,
    ): ?string {
        $normalizedPrompt = $this->normalizeSearchText($prompt);
        $bestResource = null;
        $bestScore = 0;

        foreach ($resources as $resource) {
            try {
                $schema = $connector->getSchema($resource)[0] ?? null;
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($schema)) {
                continue;
            }

            $score = 0;

            $score += $this->scoreTextAgainstPrompt($normalizedPrompt, (string) $resource, 5);

            foreach (($schema['columns'] ?? []) as $column) {
                $columnName = (string) ($column['name'] ?? '');
                if ($columnName === '') {
                    continue;
                }

                $score += $this->scoreTextAgainstPrompt($normalizedPrompt, $columnName, 5)
                    + $this->columnSemanticScore($columnName);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestResource = (string) $resource;
            }
        }

        return $bestScore > 0 ? $bestResource : null;
    }

    private function inferResourceFromGroupValues(
        string $prompt,
        array $profiles,
        DatabaseConnector $connector,
    ): ?string {
        foreach ($profiles as $profile) {
            $resource = (string) ($profile['resource'] ?? '');
            $groupColumn = (string) ($profile['detected']['group_column'] ?? '');

            if ($resource === '' || $groupColumn === '') {
                continue;
            }

            $match = $this->findGroupValueMatch($connector, $resource, $groupColumn, $prompt);
            if ($match !== null) {
                return $resource;
            }
        }

        return null;
    }

    private function findGroupValueMatch(
        DatabaseConnector $connector,
        string $resource,
        string $groupColumn,
        string $prompt,
    ): ?array {
        $points = $connector->aggregateByGroup($resource, $groupColumn, 'count', null, [], 500);
        $normalizedPrompt = $this->normalizeSearchText($prompt);
        $booleanPromptValue = $this->inferBooleanPromptValue($normalizedPrompt, $groupColumn);

        foreach ($points as $point) {
            $label = (string) ($point['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $normalizedLabel = $this->normalizeSearchText($label);
            if ($normalizedLabel !== '' && preg_match('/\b' . preg_quote($normalizedLabel, '/') . '\b/', $normalizedPrompt) === 1) {
                return $point;
            }

            if ($booleanPromptValue !== null && $this->matchesBooleanLabel($normalizedLabel, $booleanPromptValue)) {
                return $point;
            }
        }

        return null;
    }

    private function normalizeSearchText(string $value): string
    {
        $value = Str::lower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim($value);
    }

    private function tokenizeSearchText(string $value): array
    {
        $normalized = $this->normalizeSearchText($value);
        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalized), fn(string $token) => $token !== ''));
    }

    private function normalizedTermExistsInPrompt(string $normalizedPrompt, string $value): bool
    {
        $normalizedValue = $this->normalizeSearchText($value);

        return $normalizedValue !== ''
            && preg_match('/\b' . preg_quote($normalizedValue, '/') . '\b/', $normalizedPrompt) === 1;
    }

    private function scoreTextAgainstPrompt(string $normalizedPrompt, string $value, int $baseWeight = 1): int
    {
        $normalizedValue = $this->normalizeSearchText($value);
        if ($normalizedValue === '') {
            return 0;
        }

        if (preg_match('/\b' . preg_quote($normalizedValue, '/') . '\b/', $normalizedPrompt) === 1) {
            return $baseWeight * 10;
        }

        return ($this->scoreTermOverlap($normalizedPrompt, $normalizedValue)
            + $this->semanticAssociationScore($normalizedPrompt, $normalizedValue)) * $baseWeight;
    }

    private function scoreTermOverlap(string $left, string $right): int
    {
        $leftTokens = $this->tokenizeSearchText($left);
        $rightTokens = $this->tokenizeSearchText($right);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0;
        }

        return count(array_intersect($leftTokens, $rightTokens));
    }

    private function semanticAssociationScore(string $normalizedPrompt, string $normalizedValue): int
    {
        $promptTokens = $this->tokenizeSearchText($normalizedPrompt);
        $valueTokens = $this->tokenizeSearchText($normalizedValue);
        if ($promptTokens === [] || $valueTokens === []) {
            return 0;
        }

        $score = 0;
        $semanticGroups = [
            [
                'prompt' => ['traffic', 'congestion', 'parking', 'vehicle', 'vehicles', 'movement', 'mobility'],
                'value' => ['vehicle', 'vehicles', 'movement', 'movements', 'parking', 'violation', 'license', 'plate', 'timestamp'],
                'weight' => 3,
            ],
            [
                'prompt' => ['commencement', 'graduation', 'ceremony', 'event'],
                'value' => ['vehicle', 'movement', 'parking', 'student', 'timestamp', 'created', 'date', 'time'],
                'weight' => 2,
            ],
            [
                'prompt' => ['student', 'students', 'enrolled', 'registrar'],
                'value' => ['student', 'students', 'enrolled', 'student_name', 'student_number'],
                'weight' => 3,
            ],
        ];

        foreach ($semanticGroups as $group) {
            if ($this->tokenSetsIntersect($promptTokens, $group['prompt']) && $this->tokenSetsIntersect($valueTokens, $group['value'])) {
                $score += (int) $group['weight'];
            }
        }

        return $score;
    }

    private function columnSemanticScore(string $columnName): int
    {
        $normalized = $this->normalizeSearchText($columnName);
        $score = 0;

        if (preg_match('/\b(status|type|category|classification|class|role|state|priority)\b/', $normalized) === 1) {
            $score += 6;
        }

        if (preg_match('/\b(vehicle|color|make|model|sticker|resolved|enrolled)\b/', $normalized) === 1) {
            $score += 3;
        }

        if (preg_match('/\b(id|uuid|token|email|username|password|plate|number|ip|address|key|payload)\b/', $normalized) === 1) {
            $score -= 4;
        }

        return $score;
    }

    private function candidateGroupColumns(array $profile): array
    {
        $columns = array_map(fn(array $column) => (string) ($column['name'] ?? ''), $profile['columns'] ?? []);
        $columns = array_values(array_filter($columns, function (string $column) {
            if ($column === '') {
                return false;
            }

            return !preg_match('/(^id$|_id$|uuid|token|password|email|username|plate|number|ip_address|payload|key$|owner$)/i', $column);
        }));

        return array_values(array_unique(array_merge(
            array_values(array_filter($profile['group_columns'] ?? [], 'is_string')),
            $columns,
        )));
    }

    private function scoreGroupColumnForPrompt(string $prompt, string $columnName): int
    {
        $normalizedPrompt = $this->normalizeSearchText($prompt);

        return $this->scoreTextAgainstPrompt($normalizedPrompt, $columnName, 5)
            + $this->columnSemanticScore($columnName);
    }

    private function findBestGroupValueMatch(
        DatabaseConnector $connector,
        string $resource,
        array $profile,
        string $prompt,
    ): ?array {
        $candidateColumns = $this->candidateGroupColumns($profile);
        if ($candidateColumns === []) {
            return null;
        }

        usort($candidateColumns, fn(string $left, string $right) => $this->scoreGroupColumnForPrompt($prompt, $right) <=> $this->scoreGroupColumnForPrompt($prompt, $left));

        foreach ($candidateColumns as $column) {
            $match = $this->findGroupValueMatch($connector, $resource, $column, $prompt);
            if ($match !== null) {
                return [
                    'column' => $column,
                    'match' => $match,
                ];
            }
        }

        return null;
    }

    private function inferBooleanPromptValue(string $normalizedPrompt, string $groupColumn): ?bool
    {
        $columnTokens = $this->tokenizeSearchText($groupColumn);
        if ($columnTokens === []) {
            return null;
        }

        $joinedColumn = implode(' ', $columnTokens);
        $negativePatterns = [
            '\bnot ' . preg_quote($joinedColumn, '/') . '\b',
            '\bnon ' . preg_quote($joinedColumn, '/') . '\b',
            '\bno ' . preg_quote($joinedColumn, '/') . '\b',
            '\bwithout ' . preg_quote($joinedColumn, '/') . '\b',
        ];

        foreach ($negativePatterns as $pattern) {
            if (preg_match('/' . $pattern . '/', $normalizedPrompt) === 1) {
                return false;
            }
        }

        if (preg_match('/\bnot enrolled\b/', $normalizedPrompt) === 1 && in_array('enrolled', $columnTokens, true)) {
            return false;
        }

        if (preg_match('/\b' . preg_quote($joinedColumn, '/') . '\b/', $normalizedPrompt) === 1) {
            return true;
        }

        if (preg_match('/\benrolled\b/', $normalizedPrompt) === 1 && in_array('enrolled', $columnTokens, true)) {
            return true;
        }

        return null;
    }

    private function matchesBooleanLabel(string $normalizedLabel, bool $expected): bool
    {
        $truthy = ['true', '1', 'yes', 'y', 'active', 'enabled'];
        $falsy = ['false', '0', 'no', 'n', 'inactive', 'disabled'];

        return $expected
            ? in_array($normalizedLabel, $truthy, true)
            : in_array($normalizedLabel, $falsy, true);
    }

    private function projectionResourcePreferenceScore(string $normalizedPrompt, array $profile): int
    {
        $score = 0;
        $resourceName = $this->normalizeSearchText((string) ($profile['resource'] ?? ''));
        $columnNames = array_values(array_filter(array_map(fn($column) => $this->normalizeSearchText((string) $column), (array) ($profile['column_names'] ?? []))));

        if (preg_match('/\b(traffic|congestion|vehicle|parking|movement)\b/', $normalizedPrompt) === 1) {
            if (preg_match('/\b(vehicle|movement|parking|violation)\b/', $resourceName) === 1) {
                $score += 8;
            }

            if (preg_match('/\b(movement|movements|log|logs)\b/', $resourceName) === 1) {
                $score += 18;
            }

            foreach ($columnNames as $columnName) {
                if (preg_match('/\b(timestamp|entry time|date identified|date|time)\b/', $columnName) === 1) {
                    $score += 4;
                }

                if (preg_match('/\btimestamp\b/', $columnName) === 1) {
                    $score += 10;
                }
            }
        }

        $bestDateColumn = $this->resolveDateColumn($profile);
        if ($bestDateColumn !== null) {
            $score += max($this->dateColumnScore($bestDateColumn), 0);
        }

        return $score;
    }

    private function dateColumnScore(string $columnName): int
    {
        $normalized = $this->normalizeSearchText($columnName);
        $score = 0;

        if (preg_match('/\b(timestamp|entry time|event date|date identified|incident date|log date)\b/', $normalized) === 1) {
            $score += 12;
        }

        if (preg_match('/\b(date|time)\b/', $normalized) === 1) {
            $score += 6;
        }

        if (preg_match('/\bcreated at\b/', $normalized) === 1) {
            $score += 3;
        }

        if (preg_match('/\bupdated at\b/', $normalized) === 1) {
            $score -= 1;
        }

        if (preg_match('/\bdeleted at\b/', $normalized) === 1) {
            $score -= 8;
        }

        return $score;
    }

    private function tokenSetsIntersect(array $left, array $right): bool
    {
        return array_intersect($left, $right) !== [];
    }
}
