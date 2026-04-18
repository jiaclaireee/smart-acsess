<?php

namespace App\Services\Chatbot;

use App\Models\ConnectedDatabase;
use App\Models\User;
use App\Services\Chatbot\Contracts\ChatbotLanguageModel;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorException;
use App\Services\Database\DatabaseConnectorManager;
use Illuminate\Support\Str;

class UnifiedChatbotService
{
    public function __construct(
        protected AccessibleDatabaseResolver $databaseResolver,
        protected ChatbotContextBuilder $contextBuilder,
        protected ChatbotHistoryStore $historyStore,
        protected ChatbotKnowledgeIndexService $knowledgeIndex,
        protected ChatbotSchemaTrainer $schemaTrainer,
        protected ChatbotQueryInterpreter $queryInterpreter,
        protected MultilingualQueryProcessor $queryProcessor,
        protected DatabaseConnectorManager $manager,
        protected ChatbotLanguageModel $languageModel,
        protected OfficialUniversityKnowledgeService $universityKnowledge,
        protected ProjectionService $projectionService,
    ) {
    }

    public function prepareContext(
        User $user,
        ?int $databaseId = null,
        ?string $resource = null,
        string|int|null $conversationId = null,
    ): array
    {
        $scopeKey = $this->scopeKey($databaseId, $resource);
        $context = $this->buildContext($user, $databaseId, $resource);
        $stored = $this->historyStore->rememberContext($user, $scopeKey, $context, [
            'database_id' => $databaseId,
            'resource' => $resource,
        ]);
        $conversation = $conversationId !== null
            ? $this->historyStore->getConversation($user, $conversationId)
            : null;

        return [
            'context_id' => $stored['id'],
            'history' => $conversation['messages'] ?? [],
            'conversation' => $conversation['conversation'] ?? null,
            ...$context,
        ];
    }

    public function ask(User $user, array $payload): array
    {
        $databaseId = isset($payload['db_id']) ? (int) $payload['db_id'] : null;
        $resource = isset($payload['resource']) && trim((string) $payload['resource']) !== ''
            ? trim((string) $payload['resource'])
            : null;
        $scopeKey = $this->scopeKey($databaseId, $resource);
        $conversationId = isset($payload['conversation_id']) && $payload['conversation_id'] !== ''
            ? (string) $payload['conversation_id']
            : null;
        $startNewConversation = (bool) ($payload['new_conversation'] ?? false);
        $conversation = $this->historyStore->resolveConversation(
            $user,
            $scopeKey,
            $resource,
            $conversationId,
            $startNewConversation,
            ['database_id' => $databaseId, 'resource' => $resource]
        );
        $contextPayload = $this->loadOrPrepareContext($user, $databaseId, $resource, $payload['context_id'] ?? null);
        $context = $contextPayload['context'];
        $history = $this->historyStore->getHistory($user, $scopeKey, null, $conversation->id);
        $prompt = trim((string) $payload['prompt']);
        $query = $this->queryInterpreter->interpret(
            $prompt,
            (array) ($context['training_profile'] ?? []),
            $history
        )->toArray();
        $query = $this->mergeLanguageModelInterpretation($prompt, $context, $history, $query);
        $conversationTurn = $this->resolveConversationTurn($prompt, $query, $history, $context);
        $effectivePrompt = $conversationTurn['prompt'] ?? $prompt;
        $effectiveQuery = $conversationTurn['query'] ?? $query;
        $externalKnowledge = $this->universityKnowledge->search($prompt);
        $analysis = $this->analyze($user, $effectivePrompt, $effectiveQuery, $context, $externalKnowledge, $conversationTurn);
        $analysis['answer'] = $this->finalizeAnswer($prompt, $context, $analysis, $effectiveQuery['language_style']);

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
            'sources' => $analysis['sources'],
            'conversation_state' => [
                'resolved_prompt' => $effectivePrompt,
                'normalized_prompt' => $effectiveQuery['normalized'] ?? null,
                'semantic_text' => $effectiveQuery['semantic_text'] ?? null,
                'language_style' => $effectiveQuery['language_style'] ?? 'english',
                'date_scope' => $effectiveQuery['date_scope'] ?? null,
                'follow_up' => (bool) ($conversationTurn['inherited'] ?? false),
                'interpretation_confidence' => (float) ($effectiveQuery['interpretation_confidence'] ?? 0.0),
                'resource_hints' => (array) ($effectiveQuery['resource_hints'] ?? []),
                'projection_hints' => (array) ($effectiveQuery['projection_hints'] ?? []),
            ],
        ];

        $storedConversation = $this->historyStore->appendTurn(
            $user,
            $scopeKey,
            $userMessage,
            $assistantMessage,
            null,
            $conversation->id,
            false,
            ['database_id' => $databaseId, 'resource' => $resource]
        );

        return [
            'context_id' => $contextPayload['id'],
            'scope' => $scopeKey,
            'conversation' => $storedConversation['conversation'],
            'summary' => $context['summary'] ?? null,
            'overview' => $context['overview'] ?? [],
            'databases' => $context['databases'] ?? [],
            'selected_database' => $context['selected_database'] ?? null,
            'selected_resource' => $context['selected_resource'] ?? null,
            'resource_type' => $context['resource_type'] ?? 'resource',
            'answer' => $analysis['answer'],
            'intent' => $analysis['intent'],
            'grounded' => $analysis['grounded'],
            'insufficient_data' => $analysis['insufficient_data'],
            'facts' => $analysis['facts'],
            'warnings' => $analysis['warnings'],
            'suggestions' => $analysis['suggestions'],
            'table' => $analysis['table'],
            'chart' => $analysis['chart'],
            'sources' => $analysis['sources'],
            'language_style' => $effectiveQuery['language_style'],
            'interpretation_confidence' => $effectiveQuery['interpretation_confidence'] ?? null,
            'history' => $storedConversation['history'],
        ];
    }

    public function history(
        User $user,
        ?int $databaseId = null,
        ?string $resource = null,
        string|int|null $conversationId = null,
    ): array
    {
        if ($conversationId !== null) {
            $conversation = $this->historyStore->getConversation($user, $conversationId);

            return [
                'conversation' => $conversation['conversation'] ?? null,
                'messages' => $conversation['messages'] ?? [],
            ];
        }

        return [
            'messages' => $this->historyStore->getHistory($user, $this->scopeKey($databaseId, $resource)),
        ];
    }

    public function reset(
        User $user,
        ?int $databaseId = null,
        ?string $resource = null,
        string|int|null $conversationId = null,
    ): array
    {
        $conversation = $this->historyStore->reset(
            $user,
            $this->scopeKey($databaseId, $resource),
            $resource,
            $conversationId
        );

        return [
            'ok' => true,
            'conversation' => $conversation,
        ];
    }

    public function knowledgeStatus(User $user): array
    {
        return $this->knowledgeIndex->statusForUser($user);
    }

    public function syncKnowledge(User $user, array $payload = []): array
    {
        $dbIds = array_values(array_map('intval', (array) ($payload['db_ids'] ?? [])));

        return $this->knowledgeIndex->syncForUser($user, $dbIds === [] ? null : $dbIds, (bool) ($payload['force'] ?? true));
    }

    public function conversations(User $user, ?string $search = null): array
    {
        return [
            'conversations' => $this->historyStore->listConversations($user, $search),
        ];
    }

    public function conversation(User $user, string|int $conversationId): array
    {
        $conversation = $this->historyStore->getConversation($user, $conversationId);

        return [
            'conversation' => $conversation['conversation'] ?? null,
            'messages' => $conversation['messages'] ?? [],
        ];
    }

    private function loadOrPrepareContext(User $user, ?int $databaseId, ?string $resource, ?string $contextId): array
    {
        $scopeKey = $this->scopeKey($databaseId, $resource);

        if (is_string($contextId) && trim($contextId) !== '') {
            $stored = $this->historyStore->getContext($user, trim($contextId));

            if (is_array($stored) && ($stored['scope_key'] ?? null) === $scopeKey) {
                return $stored;
            }
        }

        $context = $this->buildContext($user, $databaseId, $resource);

        return $this->historyStore->rememberContext($user, $scopeKey, $context, [
            'database_id' => $databaseId,
            'resource' => $resource,
        ]);
    }

    private function buildContext(User $user, ?int $databaseId, ?string $resource): array
    {
        if ($databaseId !== null) {
            $database = $this->resolveDatabase($user, $databaseId);
            $connector = $this->manager->for($database);
            $context = $this->contextBuilder->build($database, $connector, $resource);

            return [
                ...$context,
                'databases' => [$database->publicMetadata()],
                'selected_database' => $database->publicMetadata(),
                'resource_profiles' => $this->tagProfiles($database, $context['resource_profiles'] ?? []),
                'overview' => [
                    ...($context['overview'] ?? []),
                    'accessible_database_count' => 1,
                ],
            ];
        }

        $knowledge = $this->knowledgeIndex->loadKnowledgeForUser($user, (bool) config('chatbot.knowledge.refresh_on_ask', true));
        $profiles = [];
        $trainingProfiles = [];

        foreach ($knowledge['snapshots'] ?? [] as $snapshot) {
            if (is_array($snapshot['training_profile'] ?? null)) {
                $trainingProfiles[] = (array) $snapshot['training_profile'];
            }

            foreach ((array) ($snapshot['resource_profiles'] ?? []) as $profile) {
                $profiles[] = [
                    ...$profile,
                    'database_id' => $snapshot['database']['id'] ?? null,
                    'database_name' => $snapshot['database']['name'] ?? 'Database',
                    'resource_type' => $snapshot['resource_type'] ?? 'resource',
                ];
            }
        }

        usort($profiles, fn(array $left, array $right) => (int) ($right['record_count'] ?? 0) <=> (int) ($left['record_count'] ?? 0));
        $statusWarnings = [];

        foreach ($knowledge['statuses'] ?? [] as $status) {
            if (($status['status'] ?? null) !== 'ready') {
                $statusWarnings[] = sprintf('%s is %s in the chatbot knowledge index.', $status['database']['name'] ?? 'Database', $status['status']);
            }
        }

        $trainingProfile = $this->schemaTrainer->mergeTrainingProfiles($trainingProfiles, $profiles);

        return [
            'databases' => $knowledge['databases'] ?? [],
            'selected_database' => null,
            'resource_type' => 'resource',
            'selected_resource' => null,
            'resource_profiles' => $profiles,
            'training_profile' => $trainingProfile,
            'overview' => [
                'accessible_database_count' => count($knowledge['databases'] ?? []),
                'ready_database_count' => count(array_filter($knowledge['statuses'] ?? [], fn(array $row) => ($row['status'] ?? null) === 'ready')),
                'profiled_resource_count' => count($profiles),
                'known_record_total' => array_sum(array_map(fn(array $profile) => (int) ($profile['record_count'] ?? 0), $profiles)),
                'date_ready_resources' => count(array_filter($profiles, fn(array $profile) => !empty($profile['detected']['date_column']))),
            ],
            'summary' => sprintf(
                'The chatbot can currently use %d accessible databases with %d profiled data sources and %d known records.',
                count($knowledge['databases'] ?? []),
                count($profiles),
                array_sum(array_map(fn(array $profile) => (int) ($profile['record_count'] ?? 0), $profiles))
            ),
            'insufficiencies' => $statusWarnings,
            'suggested_prompts' => [
                'Show me summary of the available data.',
                'Ilan ang total records natin this month?',
                'Ano ang trend ng reports this year?',
                'May growth ba this quarter?',
                'What are the top categories across the data?',
                'Give me a simple explanation of the current data.',
            ],
        ];
    }

    private function analyze(User $user, string $prompt, array $query, array $context, array $externalKnowledge, array $conversationTurn = []): array
    {
        $semantic = $query['semantic_text'] ?? $query['normalized'] ?? '';
        $profilePool = $conversationTurn['preferred_profiles'] ?? [];
        $profiles = $profilePool !== [] ? $profilePool : ($context['resource_profiles'] ?? []);

        if (($externalKnowledge['matches'] ?? []) !== [] && $this->universityKnowledge->shouldAnswerDirectly($prompt)) {
            $summary = $this->universityKnowledge->summarizeForAnswer($prompt, $externalKnowledge);

            return $this->response(
                'external_knowledge',
                $summary ?? 'I could not find a matching official knowledge source for that question.',
                array_slice((array) ($externalKnowledge['facts'] ?? []), 0, 3),
                (array) ($externalKnowledge['warnings'] ?? []),
                $context['suggested_prompts'] ?? [],
                null,
                null,
                [],
                $summary !== null,
            );
        }

        $lookupRequest = $this->resolveLookupRequest($query, $profiles);
        $intent = $this->detectIntent($query, $lookupRequest);

        $countEntityDescriptors = $intent === 'count'
            ? $this->extractEntityDescriptors($semantic)
            : [];

        $sources = $lookupRequest !== null
            ? $this->pickLookupSources($semantic, $profiles, $lookupRequest, $query)
            : $this->pickSources($semantic, $profiles, $intent, $query['date_scope'] !== null, $countEntityDescriptors, $query);

        if ($sources === [] && $profiles !== ($context['resource_profiles'] ?? [])) {
            $profiles = $context['resource_profiles'] ?? [];
            $lookupRequest = $this->resolveLookupRequest($query, $profiles);
            $intent = $this->detectIntent($query, $lookupRequest);
            $countEntityDescriptors = $intent === 'count'
                ? $this->extractEntityDescriptors($semantic)
                : [];
            $sources = $lookupRequest !== null
                ? $this->pickLookupSources($semantic, $profiles, $lookupRequest, $query)
                : $this->pickSources($semantic, $profiles, $intent, $query['date_scope'] !== null, $countEntityDescriptors, $query);
        }

        if ($this->shouldClarifyInterpretation($query, $intent, $sources, $lookupRequest, $profiles)) {
            return $this->answerClarification($context, $query, $intent, $sources);
        }

        return match ($intent) {
            'lookup' => $this->answerLookup($user, $query, $context, $sources, $lookupRequest),
            'count' => $this->answerCount($user, $query, $context, $sources),
            'trend' => $this->answerTrend($user, $query, $context, $sources),
            'growth' => $this->answerGrowth($user, $query, $context, $sources),
            'top_categories' => $this->answerTopCategories($user, $query, $context, $sources),
            'schema' => $this->answerSchema($user, $query, $context, $sources),
            'preview' => $this->answerPreview($user, $query, $context, $sources),
            'list_resources' => $this->answerListResources($context),
            'projection' => $this->answerProjection($user, $prompt, $sources, $externalKnowledge, $query['language_style']),
            default => $this->answerSummary($context, $sources),
        };
    }

    private function detectIntent(array $query, ?array $lookupRequest = null): string
    {
        $semantic = $query['semantic_text'] ?? $query['normalized'] ?? '';

        if ($lookupRequest !== null) {
            return 'lookup';
        }

        $candidateIntent = (string) ($query['intent_candidates'][0]['intent'] ?? '');
        if ($candidateIntent !== '') {
            return $candidateIntent;
        }

        return match (true) {
            preg_match('/\b(growth|increase|decrease|lumago|tumaas|bumaba)\b/', $semantic) === 1 => 'growth',
            preg_match('/\b(projection|forecast|predict|estimate|projected|expected|inaasahan|inaasahang)\b/', $semantic) === 1
                || (
                    preg_match('/\b(traffic|volume|trapiko)\b/', $semantic) === 1
                    && preg_match('/\b(campus|april|may|june|july|august|september|october|november|december|january|february|march|202[0-9])\b/', $semantic) === 1
                ) => 'projection',
            preg_match('/\b(how many|count|record count|total records|ilan|gaano karami|number of)\b/', $semantic) === 1
                || (
                    preg_match('/\b(how|ilan|gaano|total|number)\b/', $semantic) === 1
                    && preg_match('/\b(entries?|records?|logs?|logged|movements?|applications?)\b/', $semantic) === 1
                ) => 'count',
            preg_match('/\b(trend|monthly|weekly|yearly|taon|buwan|trend ng)\b/', $semantic) === 1 => 'trend',
            preg_match('/\b(top|categories|breakdown|distribution|pinakamarami)\b/', $semantic) === 1 => 'top_categories',
            preg_match('/\b(schema|columns|fields|structure)\b/', $semantic) === 1 => 'schema',
            preg_match('/\b(preview|sample|rows|pakita)\b/', $semantic) === 1 => 'preview',
            preg_match('/\b(list|available|resources|sources|databases)\b/', $semantic) === 1 => 'list_resources',
            default => 'summary',
        };
    }

    private function resolveConversationTurn(string $prompt, array $query, array $history, array $context): array
    {
        $normalized = trim((string) ($query['normalized'] ?? ''));
        if ($normalized === '') {
            return [
                'prompt' => $prompt,
                'query' => $query,
                'inherited' => false,
                'preferred_profiles' => [],
            ];
        }

        $anchor = $this->latestGroundedConversationTurn($history);
        if ($anchor === null) {
            return [
                'prompt' => $prompt,
                'query' => $query,
                'inherited' => false,
                'preferred_profiles' => [],
            ];
        }

        $currentIntent = $this->detectIntent($query);
        if (!$this->shouldInheritConversationTurn($query, $currentIntent, $anchor)) {
            return [
                'prompt' => $prompt,
                'query' => $query,
                'inherited' => false,
                'preferred_profiles' => [],
            ];
        }

        $resolvedPrompt = $this->inheritFollowUpPrompt($query, $anchor, (array) ($context['training_profile'] ?? []), $history);
        if ($resolvedPrompt === null || trim($resolvedPrompt) === '') {
            return [
                'prompt' => $prompt,
                'query' => $query,
                'inherited' => false,
                'preferred_profiles' => [],
            ];
        }

        return [
            'prompt' => $resolvedPrompt,
            'query' => $this->mergeLanguageModelInterpretation(
                $resolvedPrompt,
                $context,
                $history,
                $this->queryInterpreter->interpret(
                    $resolvedPrompt,
                    (array) ($context['training_profile'] ?? []),
                    $history
                )->toArray()
            ),
            'inherited' => true,
            'preferred_profiles' => $this->profilesFromSerializedSources(
                $context['resource_profiles'] ?? [],
                (array) ($anchor['assistant']['sources'] ?? [])
            ),
        ];
    }

    private function latestGroundedConversationTurn(array $history): ?array
    {
        for ($index = count($history) - 1; $index >= 0; $index--) {
            $assistant = $history[$index] ?? null;
            if (!is_array($assistant) || ($assistant['role'] ?? null) !== 'assistant' || !($assistant['grounded'] ?? false)) {
                continue;
            }

            if (!in_array((string) ($assistant['intent'] ?? ''), ['count', 'trend', 'growth', 'top_categories', 'projection'], true)) {
                continue;
            }

            for ($userIndex = $index - 1; $userIndex >= 0; $userIndex--) {
                $user = $history[$userIndex] ?? null;
                if (is_array($user) && ($user['role'] ?? null) === 'user') {
                    return [
                        'assistant' => $assistant,
                        'user' => $user,
                    ];
                }
            }
        }

        return null;
    }

    private function shouldInheritConversationTurn(array $query, string $currentIntent, array $anchor): bool
    {
        $normalized = trim((string) ($query['normalized'] ?? ''));
        if ($normalized === '') {
            return false;
        }

        $wordCount = count(array_values(array_filter(explode(' ', $normalized), fn(string $token) => $token !== '')));
        if ($wordCount > 8) {
            return false;
        }

        if (!in_array((string) ($anchor['assistant']['intent'] ?? ''), ['count', 'trend', 'growth', 'top_categories', 'projection'], true)) {
            return false;
        }

        if (
            !($query['conversation_hints']['follow_up_like'] ?? false)
            && $currentIntent !== 'summary'
            && $wordCount > 4
        ) {
            return false;
        }

        $explicitSubjects = array_values(array_filter(
            (array) ($query['domain_signals']['subjects'] ?? []),
            fn($subject) => is_string($subject) && trim($subject) !== ''
        ));

        if (
            $explicitSubjects !== []
            && !($query['conversation_hints']['follow_up_like'] ?? false)
            && $wordCount > 2
        ) {
            return false;
        }

        if ($query['date_scope'] !== null) {
            return true;
        }

        if (preg_match('/^(in|at|from|to|sa|near|and|then|for|during|about|what about|how about)\b/', $normalized) === 1) {
            return true;
        }

        if ($this->extractEntityDescriptors($query['semantic_text'] ?? $normalized) !== []) {
            return true;
        }

        return $wordCount <= 2;
    }

    private function inheritFollowUpPrompt(array $query, array $anchor, array $trainingProfile = [], array $history = []): ?string
    {
        $previousPrompt = (string) (
            $anchor['assistant']['conversation_state']['resolved_prompt']
            ?? $anchor['user']['content']
            ?? ''
        );
        if ($previousPrompt === '') {
            return null;
        }

        $previousInterpretation = $this->queryInterpreter->interpret($previousPrompt, $trainingProfile, $history);
        $previousNormalized = (string) (($anchor['assistant']['conversation_state']['normalized_prompt'] ?? null)
            ?: $previousInterpretation->normalized);
        $previousSemantic = (string) (($anchor['assistant']['conversation_state']['semantic_text'] ?? null)
            ?: $previousInterpretation->semanticText);
        $currentNormalized = trim((string) ($query['normalized'] ?? ''));
        $currentSemantic = trim((string) ($query['semantic_text'] ?? $currentNormalized));
        if ($previousNormalized === '' || $currentNormalized === '') {
            return null;
        }

        $basePrompt = $previousNormalized;
        $currentLocationPhrases = $this->followUpLocationPhrases($currentNormalized);
        $previousLocationPhrases = $this->followUpLocationPhrases($previousNormalized);

        if ($currentLocationPhrases !== []) {
            $basePrompt = $this->stripDescriptorPhrases($basePrompt, $previousLocationPhrases);
            $replacement = $this->locationReplacementPhrase($currentNormalized, $currentLocationPhrases);

            return $this->mergePromptFragments($basePrompt, $replacement);
        }

        $currentEntityDescriptors = $this->extractEntityDescriptors($currentSemantic);
        $previousEntityDescriptors = $this->extractEntityDescriptors($previousSemantic);

        if ($this->hasStatusEntityDescriptor($currentEntityDescriptors) && $this->hasStatusEntityDescriptor($previousEntityDescriptors)) {
            $basePrompt = $this->stripEntityDescriptorValues($basePrompt, $this->followUpReplaceableDescriptors($previousEntityDescriptors));

            return $this->mergePromptFragments($basePrompt, $this->cleanFollowUpFragment($currentNormalized));
        }

        return $this->mergePromptFragments($basePrompt, $this->cleanFollowUpFragment($currentNormalized));
    }

    private function followUpLocationPhrases(string $semantic): array
    {
        $phrases = $this->extractContextualEntityPhrases($semantic);
        if ($phrases !== []) {
            return $phrases;
        }

        $tokens = array_values(array_filter(explode(' ', trim($semantic)), fn(string $token) => $token !== ''));
        if (count($tokens) <= 2 && preg_match('/\b(today|week|month|quarter|year|count|trend|growth|top|summary)\b/', $semantic) !== 1) {
            $clean = $this->cleanDescriptorPhrase($semantic);

            return $clean !== null ? [$clean] : [];
        }

        return [];
    }

    private function stripDescriptorPhrases(string $prompt, array $phrases): string
    {
        $cleaned = $prompt;

        foreach ($phrases as $phrase) {
            $pattern = '/\b(?:in|at|from|to|sa|near)\s+' . preg_quote($phrase, '/') . '\b/';
            $cleaned = preg_replace($pattern, ' ', $cleaned) ?? $cleaned;

            $pattern = '/\b' . preg_quote($phrase, '/') . '\b/';
            $cleaned = preg_replace($pattern, ' ', $cleaned) ?? $cleaned;
        }

        return trim((string) preg_replace('/\s+/', ' ', $cleaned));
    }

    private function locationReplacementPhrase(string $currentNormalized, array $phrases): string
    {
        $fragment = $this->cleanFollowUpFragment($currentNormalized);
        if (preg_match('/^(in|at|from|to|sa|near)\b/', $fragment) === 1) {
            return $fragment;
        }

        return 'in ' . implode(' ', $phrases);
    }

    private function cleanFollowUpFragment(string $fragment): string
    {
        $fragment = preg_replace('/^(and|then|about)\s+/', '', trim($fragment)) ?? trim($fragment);
        $fragment = preg_replace('/^(what about|how about)\s+/', '', $fragment) ?? $fragment;
        $fragment = preg_replace('/\s+/', ' ', $fragment) ?? $fragment;

        return trim($fragment);
    }

    private function mergePromptFragments(string $base, string $fragment): string
    {
        $merged = trim($base . ' ' . $fragment);
        $merged = preg_replace('/\s+/', ' ', $merged) ?? $merged;

        return trim($merged);
    }

    private function stripEntityDescriptorValues(string $prompt, array $descriptors): string
    {
        $cleaned = $prompt;

        foreach ($descriptors as $descriptor) {
            foreach ($this->descriptorTerms($descriptor) as $term) {
                $pattern = '/\b' . preg_quote($term, '/') . '\b/i';
                $cleaned = preg_replace($pattern, ' ', $cleaned) ?? $cleaned;
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $cleaned));
    }

    private function followUpReplaceableDescriptors(array $descriptors): array
    {
        return array_values(array_filter(
            $descriptors,
            fn(array $descriptor) => $this->isStatusDescriptor($descriptor)
        ));
    }

    private function profilesFromSerializedSources(array $profiles, array $sources): array
    {
        $keys = [];
        foreach ($sources as $source) {
            if (!is_array($source) || empty($source['resource'])) {
                continue;
            }

            $keys[] = ($source['database_id'] ?? '-') . ':' . $source['resource'];
        }

        if ($keys === []) {
            return [];
        }

        return array_values(array_filter($profiles, function (array $profile) use ($keys) {
            return in_array(($profile['database_id'] ?? '-') . ':' . ($profile['resource'] ?? ''), $keys, true);
        }));
    }

    private function answerSummary(array $context, array $sources): array
    {
        $rows = array_map(fn(array $source) => [
            'database' => $source['database_name'] ?? '-',
            'resource' => $source['resource'] ?? '-',
            'record_count' => $source['record_count'] ?? 0,
            'date_column' => $source['detected']['date_column'] ?? null,
            'group_column' => $source['detected']['group_column'] ?? null,
        ], array_slice($sources !== [] ? $sources : ($context['resource_profiles'] ?? []), 0, 8));

        return $this->response(
            'summary',
            (string) ($context['summary'] ?? 'I prepared the accessible chatbot knowledge context.'),
            [
                sprintf('Accessible databases: %d', (int) ($context['overview']['accessible_database_count'] ?? count($context['databases'] ?? []))),
                sprintf('Prepared data sources: %d', (int) ($context['overview']['profiled_resource_count'] ?? count($context['resource_profiles'] ?? []))),
                sprintf('Known record total: %d', (int) ($context['overview']['known_record_total'] ?? 0)),
            ],
            $context['insufficiencies'] ?? [],
            $context['suggested_prompts'] ?? [],
            ['columns' => ['database', 'resource', 'record_count', 'date_column', 'group_column'], 'rows' => $rows],
            $rows === [] ? null : ['type' => 'bar', 'title' => 'Indexed data source sizes', 'labels' => array_column($rows, 'resource'), 'series' => array_column($rows, 'record_count')],
            $this->serializeSources($rows),
        );
    }

    private function answerLookup(User $user, array $query, array $context, array $sources, ?array $lookupRequest): array
    {
        if ($lookupRequest === null) {
            return $this->answerSummary($context, $sources);
        }

        $rows = [];
        $usedSources = [];
        $seenMatches = [];
        $expectsMultiple = (bool) ($lookupRequest['expects_multiple'] ?? false);

        foreach (array_slice($sources, 0, 3) as $source) {
            $result = $this->executeLookupForSource($user, $source, $lookupRequest, $context['resource_profiles'] ?? []);
            if (($result['rows'] ?? []) === []) {
                continue;
            }

            foreach ((array) ($result['used_sources'] ?? []) as $usedSource) {
                $usedSources[$this->sourceKey($usedSource)] = $usedSource;
            }

            foreach ((array) ($result['rows'] ?? []) as $lookupRow) {
                $targetValue = $lookupRow[$lookupRequest['target']['result_key']] ?? null;
                $matchKey = $expectsMultiple
                    ? strtolower((string) $targetValue)
                    : strtolower((string) $targetValue) . '|' . ($lookupRow['database'] ?? '-') . '|' . ($lookupRow['resource'] ?? '-');
                if (isset($seenMatches[$matchKey])) {
                    continue;
                }

                $seenMatches[$matchKey] = true;
                $rows[] = $lookupRow;
            }
        }

        $descriptorText = $lookupRequest['descriptor_text'] ?: 'the requested record';
        $targetLabel = $lookupRequest['target']['label'] ?? 'requested value';
        $suggestions = $context['suggested_prompts'] ?? [];

        if ($rows === []) {
            return $this->response(
                'lookup',
                $this->queryProcessor->render(
                    $query['language_style'],
                    sprintf('I could not find a grounded %s for %s in the accessible data.', $targetLabel, $descriptorText),
                    sprintf('Hindi ko makita ang grounded na %s para sa %s sa accessible data.', $targetLabel, $descriptorText),
                    sprintf('Hindi ko mahanap ang grounded na %s para sa %s sa accessible data.', $targetLabel, $descriptorText),
                ),
                [
                    sprintf('Requested field: %s', $targetLabel),
                    sprintf('Sources checked: %d', count($sources)),
                ],
                [$expectsMultiple
                    ? 'Try adding another detail like a date, owner, or source if the requested list is too broad.'
                    : 'Try adding another detail like a model, date, or source if the vehicle description is too broad.'
                ],
                $suggestions,
                null,
                null,
                $this->serializeSources($sources),
                false,
            );
        }

        $answer = count($rows) === 1
            ? $this->queryProcessor->render(
                $query['language_style'],
                sprintf('The %s for the matched %s is %s.', $targetLabel, $descriptorText, $rows[0][$lookupRequest['target']['result_key']]),
                sprintf('Ang %s ng nagtugmang %s ay %s.', $targetLabel, $descriptorText, $rows[0][$lookupRequest['target']['result_key']]),
                sprintf('Ang %s ng matched na %s ay %s.', $targetLabel, $descriptorText, $rows[0][$lookupRequest['target']['result_key']]),
            )
            : $this->queryProcessor->render(
                $query['language_style'],
                sprintf('I found %d grounded matches for %s, so here are the %s values I could verify.', count($rows), $descriptorText, $targetLabel),
                sprintf('May %d grounded matches ako para sa %s, kaya narito ang mga %s na na-verify ko.', count($rows), $descriptorText, $targetLabel),
                sprintf('May %d grounded matches ako para sa %s, kaya narito ang mga %s na na-verify ko.', count($rows), $descriptorText, $targetLabel),
            );

        if ($expectsMultiple) {
            $answer = $this->queryProcessor->render(
                $query['language_style'],
                sprintf('I found %d grounded matches for %s, and here is the verified list of %s values.', count($rows), $descriptorText, $targetLabel),
                sprintf('May %d grounded matches ako para sa %s, at narito ang na-verify na listahan ng mga %s.', count($rows), $descriptorText, $targetLabel),
                sprintf('May %d grounded matches ako para sa %s, at narito ang verified list ng mga %s.', count($rows), $descriptorText, $targetLabel),
            );
        }

        return $this->response(
            'lookup',
            $answer,
            [
                sprintf('Requested field: %s', $targetLabel),
                sprintf('Verified matches: %d', count($rows)),
                sprintf('Matched sources: %d', count($usedSources)),
            ],
            count($rows) > 1 ? ['Multiple records matched the description, so review the returned rows before treating the value as unique.'] : [],
            $suggestions,
            ['columns' => ['database', 'resource', $lookupRequest['target']['result_key'], 'matched_on'], 'rows' => $rows],
            null,
            $this->serializeSources(array_values($usedSources)),
        );
    }

    private function answerListResources(array $context): array
    {
        $rows = array_map(fn(array $profile) => [
            'database' => $profile['database_name'] ?? '-',
            'resource' => $profile['resource'] ?? '-',
        ], array_slice($context['resource_profiles'] ?? [], 0, 25));

        return $this->response('list_resources', sprintf('I found %d grounded data sources.', count($rows)), [], $context['insufficiencies'] ?? [], $context['suggested_prompts'] ?? [], ['columns' => ['database', 'resource'], 'rows' => $rows], null, $this->serializeSources($rows));
    }

    private function answerCount(User $user, array $query, array $context, array $sources): array
    {
        $dateScope = $query['date_scope'];
        $normalizedDescriptors = $this->extractEntityDescriptors((string) ($query['normalized'] ?? ''));
        $semanticDescriptors = array_values(array_filter(
            $this->extractEntityDescriptors((string) ($query['semantic_text'] ?? '')),
            fn(array $descriptor) => !in_array((string) ($descriptor['concept'] ?? ''), ['location_name', 'reviewer_name'], true)
        ));
        $entityDescriptors = $this->uniqueDescriptors(array_merge(
            $normalizedDescriptors,
            $semanticDescriptors
        ));
        [$rows, $warnings, $usedSources] = $this->executeCountAcrossSources(
            $user,
            $query,
            $context,
            $sources,
            $entityDescriptors
        );

        if ($rows === [] && $entityDescriptors !== []) {
            $fallbackSources = $this->fallbackCountSources($query, $context, $entityDescriptors, $sources);

            if ($fallbackSources !== []) {
                [$rows, $fallbackWarnings, $usedSources] = $this->executeCountAcrossSources(
                    $user,
                    $query,
                    $context,
                    $fallbackSources,
                    $entityDescriptors
                );
                $warnings = array_values(array_unique(array_merge($warnings, $fallbackWarnings)));
            }
        }

        $total = array_sum(array_column($rows, 'record_count'));
        $isFocusedSingleSourceCount = count($rows) === 1 && count($entityDescriptors) > 1;

        return $this->response(
            'count',
            $rows === []
                ? $this->queryProcessor->render(
                    $query['language_style'],
                    'I could not determine a grounded count from the accessible data.',
                    'Hindi ko matukoy ang grounded na bilang mula sa accessible data.',
                    'Hindi ko matukoy ang grounded na count mula sa accessible data.'
                )
                : $this->countAnswerText($query['language_style'], $total, $rows, $entityDescriptors),
            array_values(array_filter([
                $isFocusedSingleSourceCount ? null : sprintf('Grounded sources counted: %d', count($rows)),
                sprintf('Total records: %d', $total),
                $dateScope ? sprintf('Date scope: %s', $this->queryProcessor->dateScopeLabel($dateScope)) : null,
            ])),
            $warnings,
            $context['suggested_prompts'] ?? [],
            ['columns' => ['database', 'resource', 'record_count'], 'rows' => $rows],
            $rows === [] || $isFocusedSingleSourceCount ? null : ['type' => 'bar', 'title' => 'Record count by data source', 'labels' => array_column($rows, 'resource'), 'series' => array_column($rows, 'record_count')],
            $this->serializeSources($usedSources),
            $rows !== [],
        );
    }

    private function executeCountAcrossSources(User $user, array $query, array $context, array $sources, array $entityDescriptors): array
    {
        $dateScope = $query['date_scope'] ?? null;
        $preferDirectEntitySources = $entityDescriptors !== []
            && collect($sources)->contains(fn(array $source) => $this->descriptorBindingsForSource($source, $entityDescriptors) !== []);
        $rows = [];
        $warnings = [];
        $usedSources = [];

        foreach ($sources as $source) {
            $dateColumn = $this->dateColumn($source);
            if ($dateScope !== null && $dateColumn === null) {
                $warnings[] = sprintf('Skipped %s / %s because no date column was indexed.', $source['database_name'], $source['resource']);
                continue;
            }

            $filters = $this->dateFilters($dateScope, $dateColumn);

            if ($entityDescriptors !== []) {
                if ($preferDirectEntitySources && $this->descriptorBindingsForSource($source, $entityDescriptors) === []) {
                    continue;
                }

                $countFilters = $this->countFiltersForSource($user, $source, $entityDescriptors, $context['resource_profiles'] ?? []);
                if ($countFilters === null) {
                    continue;
                }

                $filters = array_merge($filters, $countFilters);
            }

            $rows[] = [
                'database' => $source['database_name'],
                'resource' => $source['resource'],
                'record_count' => (int) $this->connector($user, $source)->countRecords($source['resource'], $filters),
            ];
            $usedSources[] = $source;
        }

        return [$rows, $warnings, $usedSources];
    }

    private function fallbackCountSources(array $query, array $context, array $entityDescriptors, array $initialSources): array
    {
        $sourceKeys = array_map(fn(array $source) => $this->sourceKey($source), $initialSources);
        $candidates = array_values(array_filter(
            (array) ($context['resource_profiles'] ?? []),
            function (array $profile) use ($entityDescriptors, $sourceKeys) {
                return !in_array($this->sourceKey($profile), $sourceKeys, true)
                    && $this->descriptorBindingsForSource($profile, $entityDescriptors) !== [];
            }
        ));

        if ($candidates === []) {
            return [];
        }

        $semantic = (string) ($query['semantic_text'] ?? $query['normalized'] ?? '');
        usort($candidates, function (array $left, array $right) use ($query, $entityDescriptors, $semantic) {
            $leftBindings = count($this->descriptorBindingsForSource($left, $entityDescriptors));
            $rightBindings = count($this->descriptorBindingsForSource($right, $entityDescriptors));

            if ($leftBindings !== $rightBindings) {
                return $rightBindings <=> $leftBindings;
            }

            $leftSubject = $this->subjectDomainScore($query, $left);
            $rightSubject = $this->subjectDomainScore($query, $right);
            if ($leftSubject !== $rightSubject) {
                return $rightSubject <=> $leftSubject;
            }

            $leftBoost = $this->countIntentSourceBoost($semantic, $left);
            $rightBoost = $this->countIntentSourceBoost($semantic, $right);
            if ($leftBoost !== $rightBoost) {
                return $rightBoost <=> $leftBoost;
            }

            return (int) ($right['record_count'] ?? 0) <=> (int) ($left['record_count'] ?? 0);
        });

        if ($this->hasStatusEntityDescriptor($entityDescriptors)) {
            return array_slice($candidates, 0, 1);
        }

        return array_slice($candidates, 0, 6);
    }

    private function answerTrend(User $user, array $query, array $context, array $sources): array
    {
        $buckets = [];
        $used = [];

        foreach ($sources as $source) {
            $dateColumn = $this->dateColumn($source);
            if ($dateColumn === null) {
                continue;
            }

            $series = $this->connector($user, $source)->aggregateByDate($source['resource'], $dateColumn, 'count', null, $this->dateFilters($query['date_scope'], $dateColumn), 'monthly', max((int) config('chatbot.trend_limit', 12), 1));
            if ($series === []) {
                continue;
            }

            $used[] = $source;
            foreach ($series as $point) {
                $buckets[$point['label']] = ($buckets[$point['label']] ?? 0) + (float) ($point['value'] ?? 0);
            }
        }

        ksort($buckets);
        $rows = array_map(fn(string $label) => ['period' => $label, 'value' => $buckets[$label]], array_keys($buckets));

        return $this->response(
            'trend',
            $rows === []
                ? $this->queryProcessor->render(
                    $query['language_style'],
                    'I could not build a grounded trend for that question.',
                    'Hindi ako makabuo ng grounded trend para sa tanong na iyan.',
                    'Hindi ako makabuo ng grounded trend para sa tanong na iyan.'
                )
                : $this->queryProcessor->render(
                    $query['language_style'],
                    sprintf('I built a grounded monthly trend from %d data source%s.', count($used), count($used) === 1 ? '' : 's'),
                    sprintf('Nakabuo ako ng grounded monthly trend mula sa %d data source%s.', count($used), count($used) === 1 ? '' : 's'),
                    sprintf('Nakabuo ako ng grounded monthly trend mula sa %d data source%s.', count($used), count($used) === 1 ? '' : 's')
                ),
            [sprintf('Trend points returned: %d', count($rows))],
            [],
            $context['suggested_prompts'] ?? [],
            ['columns' => ['period', 'value'], 'rows' => $rows],
            $rows === [] ? null : ['type' => 'line', 'title' => 'Grounded monthly trend', 'labels' => array_column($rows, 'period'), 'series' => array_column($rows, 'value')],
            $this->serializeSources($used),
            $rows !== [],
        );
    }

    private function answerGrowth(User $user, array $query, array $context, array $sources): array
    {
        $comparison = $query['comparison_scope'];
        if ($comparison === null) {
            return $this->answerTrend($user, $query, $context, $sources);
        }

        $current = 0;
        $previous = 0;
        $used = [];

        foreach ($sources as $source) {
            $dateColumn = $this->dateColumn($source);
            if ($dateColumn === null) {
                continue;
            }

            $connector = $this->connector($user, $source);
            $current += (int) $connector->countRecords($source['resource'], $this->dateFilters($comparison['current'], $dateColumn));
            $previous += (int) $connector->countRecords($source['resource'], $this->dateFilters($comparison['previous'], $dateColumn));
            $used[] = $source;
        }

        $growth = $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null;

        return $this->response(
            'growth',
            sprintf('I compared %s. The current period has %s records versus %s previously%s.', $comparison['label'], number_format((float) $current, 0), number_format((float) $previous, 0), $growth !== null ? sprintf(', a %.1f%% change', $growth) : ''),
            array_values(array_filter([
                sprintf('Current period count: %d', $current),
                sprintf('Previous period count: %d', $previous),
                $growth !== null ? sprintf('Growth rate: %.1f%%', $growth) : null,
            ])),
            $growth === null ? ['The previous comparison window returned 0 records, so the growth percentage is not reliable.'] : [],
            $context['suggested_prompts'] ?? [],
            ['columns' => ['period', 'record_count'], 'rows' => [['period' => 'current', 'record_count' => $current], ['period' => 'previous', 'record_count' => $previous]]],
            ['type' => 'bar', 'title' => 'Current vs previous period', 'labels' => ['Current', 'Previous'], 'series' => [$current, $previous]],
            $this->serializeSources($used),
        );
    }

    private function answerTopCategories(User $user, array $query, array $context, array $sources): array
    {
        $rows = [];
        $used = [];

        foreach (array_slice($sources, 0, 5) as $source) {
            $groupColumn = $source['detected']['group_column'] ?? null;
            if (!$groupColumn) {
                continue;
            }

            $points = $this->connector($user, $source)->aggregateByGroup($source['resource'], $groupColumn, 'count', null, [], max((int) config('chatbot.top_group_limit', 6), 1));
            if ($points === []) {
                continue;
            }

            $used[] = $source;
            foreach ($points as $point) {
                $rows[] = [
                    'database' => $source['database_name'],
                    'resource' => $source['resource'],
                    'label' => $point['label'],
                    'value' => $point['value'],
                ];
            }
        }

        usort($rows, fn(array $left, array $right) => (float) $right['value'] <=> (float) $left['value']);

        return $this->response(
            'top_categories',
            $rows === []
                ? 'I could not find a grounded grouped breakdown for that question.'
                : 'Across the matched grounded data sources, these are the leading grouped results.',
            [sprintf('Grouped results returned: %d', count($rows))],
            [],
            $context['suggested_prompts'] ?? [],
            ['columns' => ['database', 'resource', 'label', 'value'], 'rows' => array_slice($rows, 0, 20)],
            $rows === [] ? null : ['type' => 'bar', 'title' => 'Top categories across matched data', 'labels' => array_column(array_slice($rows, 0, 8), 'label'), 'series' => array_column(array_slice($rows, 0, 8), 'value')],
            $this->serializeSources($used),
            $rows !== [],
        );
    }

    private function answerSchema(User $user, array $query, array $context, array $sources): array
    {
        $source = $sources[0] ?? null;
        if (!$source) {
            return $this->response('schema', 'I could not match a grounded data source for a schema answer.', [], [], $context['suggested_prompts'] ?? [], null, null, [], false);
        }

        $profile = $this->contextBuilder->buildResourceProfile($this->connector($user, $source), $source['resource'], false);
        $rows = array_map(fn(array $column) => ['name' => $column['name'] ?? null, 'type' => $column['type'] ?? null], $profile['columns'] ?? []);

        return $this->response('schema', sprintf('I matched %s / %s and found %d columns.', $source['database_name'], $source['resource'], count($rows)), [], [], $this->defaultSuggestions($source), ['columns' => ['name', 'type'], 'rows' => $rows], null, $this->serializeSources([$source]), $rows !== []);
    }

    private function answerPreview(User $user, array $query, array $context, array $sources): array
    {
        $source = $sources[0] ?? null;
        if (!$source) {
            return $this->response('preview', 'I could not match a grounded data source to preview.', [], [], $context['suggested_prompts'] ?? [], null, null, [], false);
        }

        $rows = $this->connector($user, $source)->previewRows($source['resource'], [], max((int) config('chatbot.sample_rows_limit', 5), 1));
        $columns = $rows === [] ? [] : array_keys((array) $rows[0]);

        return $this->response('preview', sprintf('Here is a grounded preview from %s / %s.', $source['database_name'], $source['resource']), [], [], $this->defaultSuggestions($source), ['columns' => $columns, 'rows' => $rows], null, $this->serializeSources([$source]), $rows !== []);
    }

    private function answerProjection(User $user, string $prompt, array $sources, array $externalKnowledge, string $languageStyle = 'english'): array
    {
        $source = collect($sources)->first(fn(array $row) => !empty($row['detected']['date_column']));
        if (!$source) {
            return $this->response(
                'projection',
                $this->queryProcessor->render(
                    $languageStyle,
                    'I could not determine a time-ready grounded data source for this projection request.',
                    'Hindi ko matukoy ang time-ready grounded data source para sa projection request na ito.',
                    'Hindi ko matukoy ang time-ready grounded data source para sa projection request na ito.'
                ),
                array_slice((array) ($externalKnowledge['facts'] ?? []), 0, 2),
                ['Projection requires a grounded resource with time-based data.'],
                $this->defaultSuggestions(),
                null,
                null,
                [],
                false
            );
        }

        $database = $this->resolveDatabase($user, (int) $source['database_id']);
        $analysis = $this->projectionService->buildProjection(
            $this->manager->for($database),
            ['database' => $database->publicMetadata(), 'selected_resource' => $source['resource'], 'resource_profiles' => [$source], 'resource_type' => $database->resourceLabel()],
            $source['resource'],
            $this->dateColumn($source),
            $prompt,
            [
                'facts' => (array) ($externalKnowledge['facts'] ?? []),
                'warnings' => (array) ($externalKnowledge['warnings'] ?? []),
                'event_related' => ($externalKnowledge['matches'] ?? []) !== [],
                'major_event' => $this->universityKnowledge->mentionsMajorCampusEvent($prompt, $externalKnowledge),
            ],
        );

        $analysis['sources'] = $this->serializeSources([$source]);
        $analysis['answer'] = $this->localizeProjectionAnswer($analysis['answer'] ?? '', $languageStyle);

        return $analysis;
    }

    private function resolveLookupRequest(array $query, array $profiles): ?array
    {
        $lookupText = $this->lookupInterpretationText($query);
        if ($lookupText === '') {
            return null;
        }

        foreach ($this->lookupTargets() as $target) {
            if (!$this->semanticIncludesAny($lookupText, $target['phrases'])) {
                continue;
            }

            $descriptors = $this->extractLookupDescriptors($lookupText, $target);
            if ($descriptors === []) {
                continue;
            }

            foreach ($profiles as $profile) {
                if ($this->lookupTargetColumn($profile, $target) !== null) {
                    return [
                        'target' => $target,
                        'descriptors' => $descriptors,
                        'descriptor_text' => implode(' ', array_column($descriptors, 'value')),
                        'expects_multiple' => $this->lookupExpectsMultiple($lookupText),
                    ];
                }
            }
        }

        return null;
    }

    private function pickLookupSources(string $semantic, array $profiles, array $lookupRequest, array $query = []): array
    {
        $scored = [];

        foreach ($profiles as $profile) {
            $score = $this->score($semantic, (string) ($profile['database_name'] ?? ''))
                + $this->score($semantic, (string) ($profile['resource'] ?? ''))
                + $this->score($semantic, (string) ($profile['description'] ?? ''));

            $targetColumn = $this->lookupTargetColumn($profile, $lookupRequest['target']);
            if ($targetColumn !== null) {
                $score += 25;
            }

            $matchedDescriptorCount = count($this->lookupFiltersForSource($profile, $lookupRequest['descriptors']));
            $score += $matchedDescriptorCount * 12;

            foreach ((array) ($profile['semantic_terms'] ?? []) as $term) {
                $score += $this->score($semantic, (string) $term);
            }

            $score += $this->hintedResourceScore($query, $profile, 'lookup');

            $scored[] = ['score' => $score, 'profile' => $profile];
        }

        usort($scored, fn(array $left, array $right) => $right['score'] <=> $left['score']);

        return array_values(array_map(
            fn(array $item) => ['score' => $item['score'], ...$item['profile']],
            array_filter($scored, fn(array $item) => $item['score'] > 0)
        ));
    }

    private function pickSources(string $semantic, array $profiles, string $intent, bool $needsDate, array $entityDescriptors = [], array $query = []): array
    {
        $scored = [];

        foreach ($profiles as $profile) {
            $score = $this->score($semantic, (string) ($profile['database_name'] ?? ''))
                + $this->score($semantic, (string) ($profile['resource'] ?? ''))
                + $this->score($semantic, (string) ($profile['description'] ?? ''));

            foreach ((array) ($profile['semantic_terms'] ?? []) as $term) {
                $score += $this->score($semantic, (string) $term);
            }

            if ($needsDate && !empty($profile['detected']['date_column'])) {
                $score += 10;
            }

            if ($intent === 'top_categories' && !empty($profile['detected']['group_column'])) {
                $score += 8;
            }

            if ($intent === 'count' && $entityDescriptors !== []) {
                $score += count($this->descriptorBindingsForSource($profile, $entityDescriptors)) * 14;
                $score += $this->countIntentSourceBoost($semantic, $profile);
            }

            $score += $this->hintedResourceScore($query, $profile, $intent);

            $scored[] = ['score' => $score, 'profile' => $profile];
        }

        usort($scored, fn(array $left, array $right) => $right['score'] <=> $left['score']);
        $sources = array_values(array_map(fn(array $item) => ['score' => $item['score'], ...$item['profile']], array_filter($scored, fn(array $item) => $item['score'] > 0)));

        if ($sources === [] && $profiles !== []) {
            $sources = array_slice($profiles, 0, 6);
        }

        if ($needsDate) {
            $sources = array_values(array_filter($sources, fn(array $source) => !empty($source['detected']['date_column'])));
        }

        if ($intent === 'count' && $entityDescriptors !== []) {
            $directMatchSources = array_values(array_filter(
                $sources,
                fn(array $source) => $this->descriptorBindingsForSource($source, $entityDescriptors) !== []
            ));

            if ($directMatchSources !== []) {
                usort($directMatchSources, function (array $left, array $right) use ($entityDescriptors) {
                    $leftBindings = count($this->descriptorBindingsForSource($left, $entityDescriptors));
                    $rightBindings = count($this->descriptorBindingsForSource($right, $entityDescriptors));

                    if ($leftBindings === $rightBindings) {
                        return (float) ($right['score'] ?? 0.0) <=> (float) ($left['score'] ?? 0.0);
                    }

                    return $rightBindings <=> $leftBindings;
                });

                $sources = $directMatchSources;
            }
        }

        if ($intent === 'count' && $entityDescriptors !== []) {
            $subjectFocusedSources = array_values(array_filter(
                $sources,
                fn(array $source) => $this->subjectDomainScore($query, $source) > 0
            ));

            if ($subjectFocusedSources !== []) {
                usort($subjectFocusedSources, function (array $left, array $right) use ($query) {
                    $leftScore = $this->subjectDomainScore($query, $left);
                    $rightScore = $this->subjectDomainScore($query, $right);

                    if ($leftScore === $rightScore) {
                        return (float) ($right['score'] ?? 0.0) <=> (float) ($left['score'] ?? 0.0);
                    }

                    return $rightScore <=> $leftScore;
                });

                $sources = $subjectFocusedSources;
            }
        }

        if ($intent === 'count' && $this->hasStatusEntityDescriptor($entityDescriptors)) {
            return array_slice($sources, 0, 1);
        }

        return array_slice($sources, 0, in_array($intent, ['summary', 'count', 'trend', 'growth', 'top_categories'], true) ? 6 : 1);
    }

    private function lookupTargets(): array
    {
        return [
            [
                'key' => 'license_number',
                'label' => 'license number',
                'result_key' => 'license_number',
                'phrases' => [
                    'license number',
                    'license plate',
                    'license plate number',
                    'plate number',
                    'plate no',
                    'plate #',
                    'plate',
                    'plaka',
                ],
                'column_patterns' => [
                    'license_plate_number',
                    'license_plate',
                    'plate_number',
                    'plate_no',
                    'plate_num',
                    'license_number',
                    'license_no',
                    'license_num',
                    'plate',
                ],
            ],
        ];
    }

    private function lookupExpectsMultiple(string $semantic): bool
    {
        return preg_match('/\b(list|ilista|lista|all|lahat|mga|show me|pakita mo|pakita ang)\b/', $semantic) === 1;
    }

    private function lookupInterpretationText(array $query): string
    {
        return trim((string) ($query['normalized'] ?? $query['semantic_text'] ?? ''));
    }

    private function extractLookupDescriptors(string $semantic, array $target): array
    {
        $descriptors = [];
        $captured = [];

        foreach ([
            'green', 'red', 'blue', 'black', 'white', 'silver', 'gray', 'grey', 'yellow', 'orange', 'brown',
        ] as $color) {
            if (preg_match('/\b' . preg_quote($color, '/') . '\b/', $semantic) === 1) {
                $descriptors[] = [
                    'concept' => 'color',
                    'value' => $color,
                    'column_patterns' => ['color', 'colour', 'kulay'],
                ];
                $captured[$color] = true;
            }
        }

        foreach ($this->vehicleTypeDefinitions() as $definition) {
            foreach ($definition['aliases'] as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $semantic) !== 1) {
                    continue;
                }

                $descriptors[] = [
                    'concept' => 'vehicle_type',
                    'value' => $definition['value'],
                    'aliases' => $definition['aliases'],
                    'column_patterns' => ['vehicle_type', 'vehicle_class', 'type', 'class', 'body', 'category', 'model', 'description'],
                ];
                $captured[$definition['value']] = true;
                foreach ($definition['aliases'] as $capturedAlias) {
                    $captured[$capturedAlias] = true;
                }

                break;
            }
        }

        $residual = $semantic;
        foreach ((array) ($target['phrases'] ?? []) as $phrase) {
            $residual = str_replace($phrase, ' ', $residual);
        }

        $residual = preg_replace('/\b(what|is|the|of|for|ano|ang|ng|yung|iyong|give|me|show|tell|please|number|vehicle|vehicles|car|cars|list|ilista|lista|mo|mga|lahat|registered|registration|rehistrado|nakarehistro|recorded|records|record|logged|logs|log|captured|listed|available|ilan|count|total|how|many|gaano|karami)\b/', ' ', $residual) ?? '';
        $residual = preg_replace('/\s+/', ' ', $residual) ?? '';

        foreach (explode(' ', trim($residual)) as $token) {
            if ($token === '' || isset($captured[$token]) || strlen($token) < 3) {
                continue;
            }

            $descriptors[] = [
                'concept' => 'keyword',
                'value' => $token,
                'column_patterns' => ['model', 'name', 'brand', 'make', 'description', 'remarks', 'details', 'type', 'class', 'category'],
            ];
        }

        return $descriptors;
    }

    private function extractEntityDescriptors(string $semantic): array
    {
        $descriptors = [];

        foreach ([
            'green', 'red', 'blue', 'black', 'white', 'silver', 'gray', 'grey', 'yellow', 'orange', 'brown',
        ] as $color) {
            if (preg_match('/\b' . preg_quote($color, '/') . '\b/', $semantic) === 1) {
                $descriptors[] = [
                    'concept' => 'color',
                    'value' => $color,
                    'column_patterns' => ['color', 'colour', 'kulay'],
                ];
            }
        }

        foreach ($this->vehicleTypeDefinitions() as $definition) {
            foreach ($definition['aliases'] as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $semantic) !== 1) {
                    continue;
                }

                $descriptors[] = [
                    'concept' => 'vehicle_type',
                    'value' => $definition['value'],
                    'aliases' => $definition['aliases'],
                    'column_patterns' => ['vehicle_type', 'vehicle_class', 'type', 'class', 'body', 'category', 'model', 'description'],
                ];

                break;
            }
        }

        if (preg_match('/\b(in|at|from|to|sa|gate|location|place|site|area|zone|entry|entries|logged|log|logs)\b/', $semantic) === 1) {
            foreach ($this->extractContextualEntityPhrases($semantic) as $phrase) {
                $descriptors[] = [
                    'concept' => 'location_name',
                    'value' => $phrase,
                    'column_patterns' => [
                        'gate_name', 'gate', 'entry_gate', 'exit_gate', 'location', 'location_name',
                        'place', 'site', 'area', 'zone', 'campus_gate', 'gate_location', 'checkpoint',
                    ],
                ];
            }
        }

        foreach ($this->statusDescriptors($semantic) as $descriptor) {
            $descriptors[] = $descriptor;
        }

        foreach ($this->reviewerDescriptors($semantic) as $descriptor) {
            $descriptors[] = $descriptor;
        }

        return $this->uniqueDescriptors($descriptors);
    }

    private function statusDescriptors(string $semantic): array
    {
        $descriptors = [];
        $definitions = [
            [
                'value' => 'approved',
                'aliases' => ['approved', 'accepted', 'cleared'],
            ],
            [
                'value' => 'enrolled',
                'aliases' => ['enrolled', 'active', 'confirmed'],
                'boolean_value' => true,
            ],
            [
                'value' => 'pending',
                'aliases' => ['pending', 'awaiting', 'processing'],
            ],
            [
                'value' => 'cancelled',
                'aliases' => ['cancelled', 'canceled', 'dropped', 'withdrawn'],
            ],
        ];

        foreach ($definitions as $definition) {
            $aliases = $definition['aliases'];
            if (
                ($definition['value'] ?? null) === 'enrolled'
                && $this->shouldTreatRegisteredAsEnrollmentStatus($semantic)
            ) {
                $aliases[] = 'registered';
            }

            foreach ($aliases as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $semantic) !== 1) {
                    continue;
                }

                $descriptors[] = [
                    'concept' => 'status_value',
                    'value' => $definition['value'],
                    'aliases' => $aliases,
                    'column_patterns' => [
                        'enrollment_status',
                        'student_status',
                        'approval_status',
                        'status',
                        'state',
                        'enrolled',
                        'is_enrolled',
                    ],
                    'boolean_value' => $definition['boolean_value'] ?? null,
                ];

                break;
            }
        }

        return $descriptors;
    }

    private function shouldTreatRegisteredAsEnrollmentStatus(string $semantic): bool
    {
        return preg_match('/\bregistered\b/', $semantic) === 1
            && preg_match('/\b(student|students|learner|learners|enrollee|enrollees|enrollment|registrant|registrants|student_enrollments)\b/', $semantic) === 1;
    }

    private function vehicleTypeDefinitions(): array
    {
        return [
            ['value' => 'sedan', 'aliases' => ['sedan']],
            ['value' => 'suv', 'aliases' => ['suv']],
            ['value' => 'truck', 'aliases' => ['truck']],
            ['value' => 'van', 'aliases' => ['van']],
            ['value' => 'motorcycle', 'aliases' => ['motorcycle']],
            ['value' => 'tricycle', 'aliases' => ['tricycle']],
            ['value' => 'bus', 'aliases' => ['bus']],
            ['value' => 'jeep', 'aliases' => ['jeep', 'jeepney']],
            ['value' => 'ebike', 'aliases' => ['ebike', 'e bike', 'e-bike', 'electric bike', 'electric bicycle']],
            ['value' => 'pickup', 'aliases' => ['pickup']],
            ['value' => 'hatchback', 'aliases' => ['hatchback']],
        ];
    }

    private function reviewerDescriptors(string $semantic): array
    {
        $descriptors = [];

        foreach ($this->reviewerPhrases($semantic) as $reviewer) {
            $descriptors[] = [
                'concept' => 'reviewer_name',
                'value' => $reviewer,
                'column_patterns' => ['reviewed_by', 'reviewer', 'reviewer_name', 'approved_by', 'processed_by'],
            ];
        }

        return $descriptors;
    }

    private function reviewerPhrases(string $semantic): array
    {
        $phrases = [];
        $patterns = [
            '/\b(?:reviewed|approved|processed)\s+by\s+([a-z][a-z .]{1,60}?)(?=\s+(?:today|this|current|week|month|quarter|year|ngayon|ngayong)\b|$)/',
            '/\bby\s+((?:ms|mr|mrs|dr|prof)\.?\s+[a-z][a-z .]{1,40}|[a-z][a-z]+(?:\s+[a-z][a-z]+){0,3})(?=\s+(?:today|this|current|week|month|quarter|year|ngayon|ngayong)\b|$)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $semantic, $matches) !== 1) {
                continue;
            }

            foreach ((array) ($matches[1] ?? []) as $phrase) {
                $clean = $this->cleanReviewerPhrase((string) $phrase);
                if ($clean !== null) {
                    $phrases[] = $clean;
                }
            }
        }

        return array_values(array_unique($phrases));
    }

    private function cleanReviewerPhrase(string $phrase): ?string
    {
        $phrase = strtolower(trim($phrase));
        $phrase = preg_replace('/[^a-z0-9.\s]+/', ' ', $phrase) ?? '';
        $phrase = preg_replace('/\s+/', ' ', $phrase) ?? '';
        $phrase = trim($phrase, " .");

        if ($phrase === '') {
            return null;
        }

        $stopwords = ['today', 'this', 'current', 'week', 'month', 'quarter', 'year', 'ngayon', 'ngayong'];
        $tokens = array_values(array_filter(explode(' ', $phrase), fn(string $token) => $token !== ''));
        while ($tokens !== [] && in_array(end($tokens), $stopwords, true)) {
            array_pop($tokens);
        }

        if ($tokens === []) {
            return null;
        }

        $clean = trim(implode(' ', $tokens));

        return strlen(str_replace(' ', '', $clean)) >= 3 ? $clean : null;
    }

    private function semanticIncludesAny(string $semantic, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/', $semantic) === 1) {
                return true;
            }
        }

        return false;
    }

    private function lookupTargetColumn(array $source, array $target): ?string
    {
        $bestColumn = null;
        $bestScore = 0;

        foreach ($this->profileColumns($source) as $column) {
            $name = strtolower((string) ($column['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $score = 0;
            foreach ((array) ($target['column_patterns'] ?? []) as $pattern) {
                if ($name === strtolower($pattern)) {
                    $score += 40;
                } elseif (str_contains($name, strtolower($pattern))) {
                    $score += 18;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestColumn = (string) $column['name'];
            }
        }

        return $bestColumn;
    }

    private function lookupFiltersForSource(array $source, array $descriptors): array
    {
        $filters = [];

        foreach ($this->descriptorBindingsForSource($source, $descriptors) as $binding) {
            $filters[] = [
                'column' => $binding['column'],
                'value' => $binding['descriptor']['value'],
            ];
        }

        return $filters;
    }

    private function descriptorBindingsForSource(array $source, array $descriptors): array
    {
        $bindings = [];

        foreach ($descriptors as $descriptor) {
            $column = $this->bestLookupColumn($source, $descriptor);
            if ($column === null) {
                continue;
            }

            $bindings[] = [
                'column' => $column,
                'descriptor' => $descriptor,
            ];
        }

        return $bindings;
    }

    private function profileColumnByName(array $source, string $columnName): ?array
    {
        foreach ($this->profileColumns($source) as $column) {
            if (($column['name'] ?? null) === $columnName) {
                return $column;
            }
        }

        return null;
    }

    private function bestLookupColumn(array $source, array $descriptor): ?string
    {
        $bestColumn = null;
        $bestScore = 0;

        foreach ($this->profileColumns($source) as $column) {
            if (!$this->isLookupSearchableColumn($column)) {
                continue;
            }

            $score = $this->scoreDescriptorAgainstColumn($source, $descriptor, $column);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestColumn = (string) $column['name'];
            }
        }

        return $bestScore >= $this->minimumDescriptorScore($descriptor) ? $bestColumn : null;
    }

    private function profileColumns(array $source): array
    {
        if (!empty($source['columns']) && is_array($source['columns'])) {
            return $source['columns'];
        }

        return array_map(
            fn($name) => ['name' => $name, 'type' => null],
            (array) ($source['column_names'] ?? [])
        );
    }

    private function isLookupSearchableColumn(array $column): bool
    {
        $name = strtolower((string) ($column['name'] ?? ''));
        $type = strtolower((string) ($column['type'] ?? ''));

        if ($name === '' || preg_match('/(^id$|_id$)/', $name) === 1) {
            return false;
        }

        if ($type === '') {
            return true;
        }

        return preg_match('/char|string|text|enum|json|array|object|user-defined/', $type) === 1;
    }

    private function rowValue(array $row, string $column): mixed
    {
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        $target = strtolower($column);
        foreach ($row as $key => $value) {
            if (strtolower((string) $key) === $target) {
                return $value;
            }
        }

        return null;
    }

    private function lookupMatchLabel(array $row, array $lookupFilters): string
    {
        $parts = [];

        foreach ($lookupFilters as $filter) {
            $value = $this->rowValue($row, (string) ($filter['column'] ?? ''));
            $parts[] = sprintf(
                '%s: %s',
                (string) ($filter['column'] ?? 'field'),
                $value !== null && $value !== '' ? (string) $value : (string) ($filter['value'] ?? '')
            );
        }

        return implode(', ', $parts);
    }

    private function executeLookupForSource(User $user, array $source, array $lookupRequest, array $profiles): array
    {
        $targetColumn = $this->lookupTargetColumn($source, $lookupRequest['target']);
        if ($targetColumn === null) {
            return ['rows' => [], 'used_sources' => []];
        }

        $directBindings = $this->descriptorBindingsForSource($source, $lookupRequest['descriptors']);
        $matchedDescriptors = array_map(fn(array $binding) => $binding['descriptor']['value'], $directBindings);
        $unresolvedDescriptors = array_values(array_filter(
            $lookupRequest['descriptors'],
            fn(array $descriptor) => !in_array($descriptor['value'], $matchedDescriptors, true)
        ));

        $joinSupport = $this->joinedLookupSupport($user, $source, $unresolvedDescriptors, $profiles);
        $filters = [];

        if ($directBindings !== []) {
            $filters['contains'] = array_map(fn(array $binding) => [
                'column' => $binding['column'],
                'value' => $binding['descriptor']['value'],
            ], $directBindings);
        }

        if (($joinSupport['in_filters'] ?? []) !== []) {
            $filters['in'] = array_values($joinSupport['in_filters']);
        }

        if ($filters === []) {
            return ['rows' => [], 'used_sources' => []];
        }

        $matchedRows = $this->connector($user, $source)->previewRows(
            $source['resource'],
            $filters,
            max((int) ($lookupRequest['expects_multiple'] ?? false ? config('chatbot.lookup_result_limit', 20) : config('chatbot.sample_rows_limit', 5)), 1)
        );

        $rows = [];
        foreach ($matchedRows as $matchedRow) {
            $targetValue = $this->rowValue($matchedRow, $targetColumn);
            if ($targetValue === null || trim((string) $targetValue) === '') {
                continue;
            }

            $rows[] = [
                'database' => $source['database_name'],
                'resource' => $source['resource'],
                $lookupRequest['target']['result_key'] => (string) $targetValue,
                'matched_on' => $this->lookupEvidenceLabel($source, $matchedRow, $directBindings, $joinSupport['evidence'] ?? []),
            ];
        }

        $usedSources = [$this->sourceKey($source) => $source];
        foreach ((array) ($joinSupport['used_sources'] ?? []) as $supportSource) {
            $usedSources[$this->sourceKey($supportSource)] = $supportSource;
        }

        return [
            'rows' => $rows,
            'used_sources' => array_values($usedSources),
        ];
    }

    private function joinedLookupSupport(User $user, array $primarySource, array $descriptors, array $profiles): array
    {
        $inFilters = [];
        $evidence = [];
        $usedSources = [];

        foreach ($descriptors as $descriptor) {
            $supportMatch = $this->bestJoinedSupportSource($primarySource, $descriptor, $profiles);
            if ($supportMatch === null) {
                continue;
            }

            $supportRows = $this->connector($user, $supportMatch['source'])->previewRows(
                $supportMatch['source']['resource'],
                ['contains' => [[
                    'column' => $supportMatch['descriptor_column'],
                    'value' => $descriptor['value'],
                ]]],
                max((int) config('chatbot.lookup_join_limit', 25), 1)
            );

            if ($supportRows === []) {
                continue;
            }

            $joinValues = [];
            foreach ($supportRows as $supportRow) {
                $joinValue = $this->rowValue($supportRow, $supportMatch['support_join_column']);
                if ($joinValue === null || $joinValue === '') {
                    continue;
                }

                $joinKey = (string) $joinValue;
                $joinValues[$joinKey] = $joinValue;
                $evidence[$supportMatch['primary_join_column']][$joinKey][] = sprintf(
                    '%s.%s: %s',
                    $supportMatch['source']['resource'],
                    $supportMatch['descriptor_column'],
                    $this->rowValue($supportRow, $supportMatch['descriptor_column']) ?? $descriptor['value']
                );
            }

            if ($joinValues === []) {
                continue;
            }

            $filterKey = $supportMatch['primary_join_column'];
            if (!isset($inFilters[$filterKey])) {
                $inFilters[$filterKey] = [
                    'column' => $supportMatch['primary_join_column'],
                    'values' => array_values($joinValues),
                ];
            } else {
                $inFilters[$filterKey]['values'] = array_values(array_intersect(
                    array_map('strval', $inFilters[$filterKey]['values']),
                    array_map('strval', array_values($joinValues))
                ));
            }

            if ($inFilters[$filterKey]['values'] === []) {
                return [
                    'in_filters' => [$filterKey => $inFilters[$filterKey]],
                    'evidence' => $evidence,
                    'used_sources' => array_values($usedSources),
                ];
            }

            $usedSources[$this->sourceKey($supportMatch['source'])] = $supportMatch['source'];
        }

        return [
            'in_filters' => $inFilters,
            'evidence' => $evidence,
            'used_sources' => array_values($usedSources),
        ];
    }

    private function bestJoinedSupportSource(array $primarySource, array $descriptor, array $profiles): ?array
    {
        $best = null;
        $bestScore = 0;

        foreach ($profiles as $profile) {
            if (($profile['database_id'] ?? null) !== ($primarySource['database_id'] ?? null)) {
                continue;
            }

            if (($profile['resource'] ?? null) === ($primarySource['resource'] ?? null)) {
                continue;
            }

            $descriptorColumn = $this->bestLookupColumn($profile, $descriptor);
            if ($descriptorColumn === null) {
                continue;
            }

            $join = $this->sharedJoinColumns($primarySource, $profile);
            if ($join === null) {
                continue;
            }

            $score = 25 + $this->score((string) $descriptor['value'], (string) ($profile['resource'] ?? ''));
            if (str_contains(strtolower($descriptorColumn), 'class') || str_contains(strtolower($descriptorColumn), 'type')) {
                $score += 8;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'source' => $profile,
                    'descriptor_column' => $descriptorColumn,
                    'primary_join_column' => $join['primary'],
                    'support_join_column' => $join['support'],
                ];
            }
        }

        return $best;
    }

    private function sharedJoinColumns(array $primarySource, array $supportSource): ?array
    {
        $primaryColumns = array_map(fn(array $column) => (string) ($column['name'] ?? ''), $this->profileColumns($primarySource));
        $supportColumns = array_map(fn(array $column) => (string) ($column['name'] ?? ''), $this->profileColumns($supportSource));

        $primaryLookup = [];
        foreach ($primaryColumns as $column) {
            $primaryLookup[strtolower($column)] = $column;
        }

        $supportLookup = [];
        foreach ($supportColumns as $column) {
            $supportLookup[strtolower($column)] = $column;
        }

        $common = array_intersect(array_keys($primaryLookup), array_keys($supportLookup));
        usort($common, fn(string $left, string $right) => $this->joinColumnScore($right) <=> $this->joinColumnScore($left));

        foreach ($common as $column) {
            if ($this->joinColumnScore($column) <= 0) {
                continue;
            }

            return [
                'primary' => $primaryLookup[$column],
                'support' => $supportLookup[$column],
            ];
        }

        $primaryResource = strtolower(Str::singular((string) ($primarySource['resource'] ?? '')));
        foreach ($supportLookup as $supportColumnLower => $supportColumn) {
            if (!str_ends_with($supportColumnLower, '_id')) {
                continue;
            }

            if ($supportColumnLower === $primaryResource . '_id' && isset($primaryLookup['id'])) {
                return [
                    'primary' => $primaryLookup['id'],
                    'support' => $supportColumn,
                ];
            }
        }

        $supportResource = strtolower(Str::singular((string) ($supportSource['resource'] ?? '')));
        foreach ($primaryLookup as $primaryColumnLower => $primaryColumn) {
            if (!str_ends_with($primaryColumnLower, '_id')) {
                continue;
            }

            if ($primaryColumnLower === $supportResource . '_id' && isset($supportLookup['id'])) {
                return [
                    'primary' => $primaryColumn,
                    'support' => $supportLookup['id'],
                ];
            }
        }

        return null;
    }

    private function joinColumnScore(string $column): int
    {
        $column = strtolower($column);

        return match (true) {
            $column === 'license_plate_number' => 30,
            $column === 'student_number' => 26,
            $column === 'email' => 18,
            $column === 'username' => 18,
            str_ends_with($column, '_number') => 16,
            str_ends_with($column, '_id') => 12,
            $column === 'id' => 4,
            default => 0,
        };
    }

    private function lookupEvidenceLabel(array $source, array $row, array $directBindings, array $joinedEvidence): string
    {
        $parts = [];

        foreach ($directBindings as $binding) {
            $parts[] = sprintf(
                '%s: %s',
                $binding['column'],
                $this->rowValue($row, $binding['column']) ?? $binding['descriptor']['value']
            );
        }

        foreach ($joinedEvidence as $primaryJoinColumn => $byJoinValue) {
            $joinValue = $this->rowValue($row, $primaryJoinColumn);
            if ($joinValue === null) {
                continue;
            }

            foreach ((array) ($byJoinValue[(string) $joinValue] ?? []) as $label) {
                $parts[] = $label;
            }
        }

        return implode(', ', array_values(array_unique($parts)));
    }

    private function sourceKey(array $source): string
    {
        return (string) ($source['database_id'] ?? '-') . ':' . (string) ($source['resource'] ?? '-');
    }

    private function hintedResourceScore(array $query, array $profile, string $intent): int
    {
        $resource = strtolower((string) ($profile['resource'] ?? ''));
        if ($resource === '') {
            return 0;
        }

        $score = 0;

        foreach ((array) ($query['resource_hints'] ?? []) as $hint) {
            if (strtolower((string) ($hint['resource'] ?? '')) !== $resource) {
                continue;
            }

            $score += (int) round(((float) ($hint['score'] ?? 0.0)) * 2.5);

            if ($intent === 'projection' && ($hint['projection_ready'] ?? false)) {
                $score += 12;
            }
        }

        foreach ((array) ($query['conversation_hints']['recent_resources'] ?? []) as $recentResource) {
            if (strtolower((string) $recentResource) === $resource) {
                $score += $intent === 'projection' ? 14 : 8;
            }
        }

        if (
            $intent === 'projection'
            && in_array((string) ($profile['resource'] ?? ''), (array) ($query['projection_hints']['resource_candidates'] ?? []), true)
        ) {
            $score += 16;
        }

        return $score;
    }

    private function subjectDomainScore(array $query, array $profile): int
    {
        $subjects = (array) ($query['domain_signals']['subjects'] ?? []);
        if ($subjects === []) {
            return 0;
        }

        $haystacks = array_filter([
            strtolower((string) ($profile['resource'] ?? '')),
            strtolower((string) ($profile['description'] ?? '')),
            implode(' ', array_map(fn($term) => strtolower((string) $term), (array) ($profile['semantic_terms'] ?? []))),
        ]);
        $score = 0;

        foreach ($subjects as $subject) {
            $patterns = match ((string) $subject) {
                'student' => ['student', 'students', 'enrollment', 'enrollments', 'student_enrollments', 'learner', 'registrant'],
                'vehicle' => ['vehicle', 'vehicles', 'plate', 'license', 'vehicle_class', 'vehicle class', 'ebike', 'jeep', 'sedan'],
                'sticker' => ['sticker', 'stickers', 'sticker_application', 'sticker application', 'reviewed_by', 'reviewer'],
                'report' => ['report', 'reports', 'incident', 'incidents', 'case', 'cases'],
                default => [],
            };

            foreach ($patterns as $pattern) {
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains($haystack, strtolower($pattern))) {
                        $score += 6;
                    }
                }
            }
        }

        return $score;
    }

    private function countIntentSourceBoost(string $semantic, array $profile): int
    {
        $score = 0;
        $resource = strtolower((string) ($profile['resource'] ?? ''));
        $columns = array_map(
            fn(array $column) => strtolower((string) ($column['name'] ?? '')),
            $this->profileColumns($profile)
        );

        if (preg_match('/\b(entries?|logged|logs?|movements?)\b/', $semantic) === 1) {
            if (preg_match('/\b(entries?|movements?|logs?)\b/', $resource) === 1) {
                $score += 18;
            }

            foreach ($columns as $column) {
                if (preg_match('/\b(entry|exit|timestamp|time|logged)\b/', $column) === 1) {
                    $score += 4;
                }
            }
        }

        if (preg_match('/\b(gate|location|place|site|area|zone|campus)\b/', $semantic) === 1) {
            foreach ($columns as $column) {
                if (preg_match('/\b(gate|location|place|site|area|zone|checkpoint)\b/', $column) === 1) {
                    $score += 6;
                }
            }
        }

        if (preg_match('/\b(vehicle|vehicles|sasakyan|car|cars|motor|motors|bike|bikes|bicycle|bicycles|ebike|e bike|e-bike|electric bike|electric bicycle|sedan|suv|truck|van|motorcycle|tricycle|bus|jeep|jeepney|pickup|hatchback)\b/', $semantic) === 1) {
            if (preg_match('/\bvehicles?\b/', $resource) === 1) {
                $score += 26;
            } elseif (preg_match('/\b(vehicle_movements?|vehicle_entries?)\b/', $resource) === 1) {
                $score += 8;
            }

            foreach ($columns as $column) {
                if (preg_match('/\b(vehicle_class|vehicle_type|type|class|body|category|license_plate|plate|vehicle_color|owner_name)\b/', $column) === 1) {
                    $score += 6;
                }
            }
        }

        if (preg_match('/\b(student|students|learner|learners|enrollee|enrollees|registrant|registrants|enroll|enrolled|pending|cancelled|canceled|semester)\b/', $semantic) === 1) {
            $hasStatusColumn = collect($columns)->contains(
                fn(string $column) => preg_match('/\b(enrollment_status|student_status|approval_status|status|state|enrolled|is_enrolled)\b/', $column) === 1
            );

            if ($hasStatusColumn) {
                if (preg_match('/\b(student_enrollments?|enrollments?)\b/', $resource) === 1) {
                    $score += 28;
                } elseif (preg_match('/\bstudents?\b/', $resource) === 1) {
                    $score += 14;
                }

                foreach ($columns as $column) {
                    if (preg_match('/\b(enrollment_status|student_status|approval_status|status|state)\b/', $column) === 1) {
                        $score += 12;
                    }

                    if (preg_match('/\b(enrolled|is_enrolled)\b/', $column) === 1) {
                        $score += 10;
                    }
                }
            }
        }

        if (preg_match('/\b(sticker|stickers|application|applications|reviewed|reviewer|approved)\b/', $semantic) === 1) {
            $hasReviewerColumn = collect($columns)->contains(
                fn(string $column) => preg_match('/\b(reviewed_by|reviewer|reviewer_name|approved_by|processed_by)\b/', $column) === 1
            );

            if ($hasReviewerColumn) {
                if (preg_match('/\bsticker_applications?\b/', $resource) === 1) {
                    $score += 34;
                } elseif (preg_match('/\bstickers?\b/', $resource) === 1) {
                    $score += 12;
                }

                foreach ($columns as $column) {
                    if (preg_match('/\b(reviewed_by|reviewer|reviewer_name|approved_by|processed_by)\b/', $column) === 1) {
                        $score += 18;
                    }

                    if (preg_match('/\b(status|application_status|approval_status)\b/', $column) === 1) {
                        $score += 8;
                    }
                }
            }
        }

        return $score;
    }

    private function extractContextualEntityPhrases(string $semantic): array
    {
        $phrases = [];

        if (preg_match_all('/\b(?:in|at|from|to|sa|near)\s+([a-z0-9 ]{2,40})/', $semantic, $matches) === 1) {
            foreach ((array) ($matches[1] ?? []) as $phrase) {
                $clean = $this->cleanDescriptorPhrase((string) $phrase);
                if ($clean !== null) {
                    $phrases[] = $clean;
                }
            }
        }

        $residual = preg_replace(
            '/\b(how|many|count|total|records?|record|entries?|entry|logged|logs?|show|tell|me|please|what|is|are|the|of|for|ano|ang|ng|mga|natin|gaano|karami|number|this|current|today|week|month|quarter|year|database|data)\b/',
            ' ',
            $semantic
        ) ?? '';
        $residual = preg_replace('/\s+/', ' ', $residual) ?? '';
        $cleanResidual = $this->cleanDescriptorPhrase(trim($residual));

        if ($cleanResidual !== null) {
            $phrases[] = $cleanResidual;
        }

        return array_values(array_unique(array_filter($phrases, fn(string $phrase) => strlen($phrase) >= 3)));
    }

    private function cleanDescriptorPhrase(string $phrase): ?string
    {
        $tokens = array_values(array_filter(explode(' ', trim($phrase)), fn(string $token) => $token !== ''));
        if ($tokens === []) {
            return null;
        }

        $stopwords = [
            'this', 'current', 'today', 'week', 'month', 'quarter', 'year',
            'record', 'records', 'entry', 'entries', 'logged', 'log', 'logs',
            'count', 'total', 'number', 'database', 'data',
            'how', 'many', 'ilan', 'gaano', 'karami', 'ano', 'ang', 'ng', 'mga',
            'natin', 'na', 'ba', 'vehicle', 'vehicles', 'sasakyan',
            'in', 'at', 'from', 'to', 'near', 'sa',
        ];

        $tokens = array_values(array_filter($tokens, fn(string $token) => !in_array($token, $stopwords, true)));
        if ($tokens === []) {
            return null;
        }

        $value = trim(implode(' ', $tokens));

        return preg_match('/^[0-9]+$/', $value) === 1 ? null : $value;
    }

    private function uniqueDescriptors(array $descriptors): array
    {
        $unique = [];

        foreach ($descriptors as $descriptor) {
            $key = strtolower((string) ($descriptor['concept'] ?? '')) . '|' . strtolower((string) ($descriptor['value'] ?? ''));
            if ($key === '|' || isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $descriptor;
        }

        return array_values($unique);
    }

    private function scoreDescriptorAgainstColumn(array $source, array $descriptor, array $column): int
    {
        $name = strtolower((string) ($column['name'] ?? ''));
        if ($name === '') {
            return 0;
        }

        $score = 0;

        foreach ((array) ($descriptor['column_patterns'] ?? []) as $pattern) {
            $pattern = strtolower((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            if ($name === $pattern) {
                $score += 30;
            } elseif (str_contains($name, $pattern)) {
                $score += 14;
            }
        }

        if (($descriptor['concept'] ?? null) === 'location_name') {
            if (preg_match('/\b(gate|location|place|site|area|zone|checkpoint)\b/', $name) === 1) {
                $score += 18;
            } elseif (str_contains($name, 'name')) {
                $score += 8;
            }

            if (preg_match('/\b(entries?|movements?|logs?)\b/', strtolower((string) ($source['resource'] ?? ''))) === 1) {
                $score += 6;
            }
        }

        foreach ((array) ($source['sample_rows'] ?? []) as $row) {
            $sampleValue = $this->rowValue((array) $row, (string) ($column['name'] ?? ''));
            if (is_scalar($sampleValue) && str_contains(strtolower((string) $sampleValue), strtolower((string) $descriptor['value']))) {
                $score += 20;
                break;
            }
        }

        foreach ((array) ($source['top_groups'] ?? []) as $point) {
            $label = strtolower((string) ($point['label'] ?? ''));
            if ($label !== '' && str_contains($label, strtolower((string) ($descriptor['value'] ?? '')))) {
                $score += 16;
                break;
            }
        }

        return $score;
    }

    private function minimumDescriptorScore(array $descriptor): int
    {
        return match ($descriptor['concept'] ?? null) {
            'location_name' => 18,
            'keyword' => 10,
            default => 12,
        };
    }

    private function countFiltersForSource(User $user, array $source, array $descriptors, array $profiles): ?array
    {
        $directBindings = $this->descriptorBindingsForSource($source, $descriptors);
        $matchedDescriptors = array_map(fn(array $binding) => $binding['descriptor']['value'], $directBindings);
        $unresolvedDescriptors = array_values(array_filter(
            $descriptors,
            fn(array $descriptor) => !in_array($descriptor['value'], $matchedDescriptors, true)
        ));

        $joinSupport = $this->joinedLookupSupport($user, $source, $unresolvedDescriptors, $profiles);
        $filters = [];

        if ($directBindings !== []) {
            foreach ($directBindings as $binding) {
                $column = $this->profileColumnByName($source, $binding['column']);
                $columnName = (string) ($binding['column'] ?? '');
                $columnType = strtolower((string) ($column['type'] ?? ''));
                $booleanValue = $binding['descriptor']['boolean_value'] ?? null;

                if (
                    is_bool($booleanValue)
                    && ($columnType === 'boolean' || preg_match('/(^|_)(enrolled|is_enrolled)$/', $columnName) === 1)
                ) {
                    $filters['equals'][] = [
                        'column' => $columnName,
                        'value' => $booleanValue,
                    ];
                    continue;
                }

                $filters['contains'][] = [
                    'column' => $columnName,
                    'value' => $binding['descriptor']['value'],
                ];
            }
        }

        if (($joinSupport['in_filters'] ?? []) !== []) {
            $filters['in'] = array_values(array_filter(
                $joinSupport['in_filters'],
                fn(array $filter) => ((array) ($filter['values'] ?? [])) !== []
            ));
        }

        if ($unresolvedDescriptors !== [] && (($joinSupport['used_sources'] ?? []) === [])) {
            return null;
        }

        return $filters === [] ? null : $filters;
    }

    private function countAnswerText(string $languageStyle, int|float $total, array $rows, array $entityDescriptors): string
    {
        $formattedTotal = number_format((float) $total, 0);
        $sourceCount = count($rows);
        $descriptorLabel = implode(' ', array_column($entityDescriptors, 'value'));
        $focusedSingleSource = $entityDescriptors !== [] && $sourceCount === 1 && count($entityDescriptors) > 1;

        if ($focusedSingleSource && (float) $total <= 0) {
            $resourceLabel = $this->humanizeResourceLabel((string) ($rows[0]['resource'] ?? 'records'));
            $criteriaLabel = $this->descriptorSummary($entityDescriptors);

            return $this->queryProcessor->render(
                $languageStyle,
                sprintf('I found no matching %s for %s in the grounded data.', $resourceLabel, $criteriaLabel),
                sprintf('Wala akong nahanap na tumutugmang %s para sa %s sa grounded data.', $resourceLabel, $criteriaLabel),
                sprintf('Wala akong nahanap na matching %s para sa %s sa grounded data.', $resourceLabel, $criteriaLabel),
            );
        }

        if ($focusedSingleSource) {
            $resourceLabel = $this->humanizeResourceLabel((string) ($rows[0]['resource'] ?? 'records'));

            return $this->queryProcessor->render(
                $languageStyle,
                sprintf('I counted %s matching %s.', $formattedTotal, $resourceLabel),
                sprintf('Nabilang ko ang %s na tumutugmang %s.', $formattedTotal, $resourceLabel),
                sprintf('Naka-count ako ng %s na matching %s.', $formattedTotal, $resourceLabel),
            );
        }

        if ($entityDescriptors !== []) {
            return $this->queryProcessor->render(
                $languageStyle,
                sprintf('I counted %s matching records for %s across %d grounded data source%s.', $formattedTotal, $descriptorLabel, $sourceCount, $sourceCount === 1 ? '' : 's'),
                sprintf('Nabilang ko ang %s na tumutugmang records para sa %s mula sa %d grounded data source%s.', $formattedTotal, $descriptorLabel, $sourceCount, $sourceCount === 1 ? '' : 's'),
                sprintf('Naka-count ako ng %s na matching records para sa %s mula sa %d grounded data source%s.', $formattedTotal, $descriptorLabel, $sourceCount, $sourceCount === 1 ? '' : 's'),
            );
        }

        return $this->queryProcessor->render(
            $languageStyle,
            sprintf('I counted %s records across %d grounded data source%s.', $formattedTotal, $sourceCount, $sourceCount === 1 ? '' : 's'),
            sprintf('Nabilang ko ang %s records mula sa %d grounded data source%s.', $formattedTotal, $sourceCount, $sourceCount === 1 ? '' : 's'),
            sprintf('Naka-count ako ng %s records mula sa %d grounded data source%s.', $formattedTotal, $sourceCount, $sourceCount === 1 ? '' : 's'),
        );
    }

    private function humanizeResourceLabel(string $resource): string
    {
        $label = trim(str_replace('_', ' ', strtolower($resource)));

        return $label !== '' ? $label : 'records';
    }

    private function descriptorSummary(array $entityDescriptors): string
    {
        $parts = [];

        foreach ($entityDescriptors as $descriptor) {
            $concept = (string) ($descriptor['concept'] ?? '');
            $value = (string) ($descriptor['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $parts[] = match ($concept) {
                'reviewer_name' => sprintf('reviewed by %s', $value),
                'status_value' => $value,
                default => $value,
            };
        }

        return implode(' ', array_values(array_unique($parts)));
    }

    private function tagProfiles(ConnectedDatabase $database, array $profiles): array
    {
        return array_map(fn(array $profile) => [
            ...$profile,
            'database_id' => $database->id,
            'database_name' => $database->name,
            'resource_type' => $database->resourceLabel(),
        ], $profiles);
    }

    private function connector(User $user, array $source): DatabaseConnector
    {
        return $this->manager->for($this->resolveDatabase($user, (int) $source['database_id']));
    }

    private function resolveDatabase(User $user, int $databaseId): ConnectedDatabase
    {
        $database = $this->databaseResolver->findForUser($user, $databaseId);

        if (!$database instanceof ConnectedDatabase) {
            throw new DatabaseConnectorException('The requested database is not available for the authenticated user.');
        }

        return $database;
    }

    private function scopeKey(?int $databaseId, ?string $resource): string
    {
        return $databaseId === null ? 'global' : 'database:' . $databaseId . ':resource:' . ($resource ?: 'all');
    }

    private function shouldClarifyInterpretation(
        array $query,
        string $intent,
        array $sources,
        ?array $lookupRequest,
        array $profiles,
    ): bool {
        if ($lookupRequest !== null || $intent === 'summary' || count($profiles) <= 1) {
            return false;
        }

        $confidence = (float) ($query['interpretation_confidence'] ?? 1.0);
        if ($confidence >= (float) config('chatbot.interpretation.clarify_below', 0.45)) {
            return false;
        }

        $tokens = array_values(array_filter(
            explode(' ', trim((string) ($query['normalized'] ?? ''))),
            fn(string $token) => $token !== ''
        ));
        if (count($tokens) < 3) {
            return false;
        }

        if ($this->extractEntityDescriptors($query['semantic_text'] ?? ($query['normalized'] ?? '')) !== []) {
            return false;
        }

        if ((float) ($query['resource_hints'][0]['score'] ?? 0.0) >= 10.0) {
            return false;
        }

        if ($sources === []) {
            return true;
        }

        $topScore = (float) ($sources[0]['score'] ?? 0.0);
        $secondScore = (float) ($sources[1]['score'] ?? 0.0);

        return count($sources) > 1
            && abs($topScore - $secondScore) <= (float) config('chatbot.interpretation.ambiguous_source_gap', 4.0);
    }

    private function answerClarification(array $context, array $query, string $intent, array $sources): array
    {
        $candidateRows = array_map(fn(array $source) => [
            'database' => $source['database_name'] ?? '-',
            'resource' => $source['resource'] ?? '-',
            'score' => $source['score'] ?? 0,
        ], array_slice($sources !== [] ? $sources : ($context['resource_profiles'] ?? []), 0, 3));

        $resourceList = implode(', ', array_values(array_filter(array_map(
            fn(array $row) => (string) ($row['resource'] ?? ''),
            $candidateRows
        ))));

        return $this->response(
            $intent,
            $this->queryProcessor->render(
                $query['language_style'] ?? 'english',
                $resourceList !== ''
                    ? sprintf('I can help, but I need a bit more detail so I do not use the wrong data source. Did you mean %s?', $resourceList)
                    : 'I can help, but I need a bit more detail so I do not use the wrong data source.',
                $resourceList !== ''
                    ? sprintf('Makatutulong ako, pero kailangan ko ng kaunting dagdag na detalye para hindi maling data source ang magamit ko. Ang ibig mo ba ay %s?', $resourceList)
                    : 'Makatutulong ako, pero kailangan ko ng kaunting dagdag na detalye para hindi maling data source ang magamit ko.',
                $resourceList !== ''
                    ? sprintf('Makatutulong ako, pero kailangan ko ng kaunting dagdag na detalye para hindi maling data source ang magamit ko. Ang ibig mo ba ay %s?', $resourceList)
                    : 'Makatutulong ako, pero kailangan ko ng kaunting dagdag na detalye para hindi maling data source ang magamit ko.'
            ),
            [
                sprintf('Interpretation confidence: %.2f', (float) ($query['interpretation_confidence'] ?? 0.0)),
                sprintf('Candidate sources reviewed: %d', count($candidateRows)),
            ],
            ['Clarification requested before running a potentially inaccurate grounded query.'],
            $this->clarificationSuggestions($intent, $candidateRows, $context['suggested_prompts'] ?? []),
            $candidateRows === [] ? null : ['columns' => ['database', 'resource', 'score'], 'rows' => $candidateRows],
            null,
            $this->serializeSources(array_slice($sources, 0, 3)),
            false,
        );
    }

    private function clarificationSuggestions(string $intent, array $candidateRows, array $fallbackSuggestions): array
    {
        $resourceSuggestions = [];

        foreach ($candidateRows as $row) {
            $resource = (string) ($row['resource'] ?? '');
            if ($resource === '') {
                continue;
            }

            $resourceSuggestions[] = match ($intent) {
                'count' => sprintf('How many records are in %s?', $resource),
                'trend' => sprintf('Show monthly trend for %s.', $resource),
                'top_categories' => sprintf('What are the top categories in %s?', $resource),
                'schema' => sprintf('Show the schema for %s.', $resource),
                'preview' => sprintf('Preview %s.', $resource),
                default => sprintf('Summarize %s.', $resource),
            };
        }

        return array_values(array_unique(array_filter(array_merge(
            $resourceSuggestions,
            array_slice($fallbackSuggestions, 0, 2)
        ))));
    }

    private function hasStatusEntityDescriptor(array $descriptors): bool
    {
        foreach ($descriptors as $descriptor) {
            if ($this->isStatusDescriptor($descriptor)) {
                return true;
            }
        }

        return false;
    }

    private function isStatusDescriptor(array $descriptor): bool
    {
        return ($descriptor['concept'] ?? null) === 'status_value';
    }

    private function descriptorTerms(array $descriptor): array
    {
        $terms = array_values(array_filter(array_unique(array_map(
            fn($value) => strtolower(trim((string) $value)),
            array_merge(
                [(string) ($descriptor['value'] ?? '')],
                (array) ($descriptor['aliases'] ?? [])
            )
        ))));

        return array_values(array_filter($terms, fn(string $term) => $term !== ''));
    }

    private function score(string $prompt, string $value): int
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        return preg_match('/\b' . preg_quote($value, '/') . '\b/', $prompt) === 1
            ? 10
            : count(array_intersect(explode(' ', $prompt), explode(' ', $value)));
    }

    private function dateColumn(array $source): ?string
    {
        return $source['detected']['date_column'] ?? (($source['date_columns'][0] ?? null) ?: null);
    }

    private function dateFilters(?array $scope, ?string $dateColumn): array
    {
        if ($scope === null || $dateColumn === null) {
            return [];
        }

        return [
            'date_column' => $dateColumn,
            'from' => $scope['from'] ?? null,
            'to' => $scope['to'] ?? null,
        ];
    }

    private function serializeSources(array $sources): array
    {
        return array_values(array_map(fn(array $source) => array_filter([
            'database_id' => $source['database_id'] ?? null,
            'database_name' => $source['database_name'] ?? ($source['database'] ?? null),
            'resource' => $source['resource'] ?? null,
            'score' => $source['score'] ?? null,
        ], fn($value) => $value !== null), $sources));
    }

    private function defaultSuggestions(?array $source = null): array
    {
        if ($source !== null) {
            return [
                sprintf('Summarize %s.', $source['resource']),
                sprintf('Show monthly trend for %s.', $source['resource']),
                sprintf('What are the top categories in %s?', $source['resource']),
            ];
        }

        return [
            'Show me summary of the available data.',
            'Ilan ang total records natin this month?',
            'Ano ang trend ng reports this year?',
        ];
    }

    private function response(
        string $intent,
        string $answer,
        array $facts,
        array $warnings,
        array $suggestions,
        ?array $table,
        ?array $chart,
        array $sources,
        bool $grounded = true,
    ): array {
        return [
            'intent' => $intent,
            'grounded' => $grounded,
            'insufficient_data' => !$grounded,
            'answer' => $answer,
            'facts' => array_values(array_filter($facts)),
            'warnings' => array_values(array_filter($warnings)),
            'suggestions' => array_values(array_filter($suggestions)),
            'table' => $table,
            'chart' => $chart,
            'sources' => $sources,
        ];
    }

    private function mergeLanguageModelInterpretation(string $prompt, array $context, array $history, array $query): array
    {
        if (!(bool) config('chatbot.llm.enabled', false)) {
            return $query;
        }

        $plan = $this->languageModel->planGroundedInterpretation(
            $prompt,
            $context,
            $history,
            (array) ($context['training_profile'] ?? []),
            $query
        );

        if (!is_array($plan)) {
            return $query;
        }

        $query['semantic_hints'] = array_values(array_unique(array_merge(
            (array) ($query['semantic_hints'] ?? []),
            array_values(array_filter(array_map('strval', (array) ($plan['semantic_hints'] ?? []))))
        )));
        $query['semantic_text'] = trim((string) ($query['normalized'] ?? '') . ' ' . implode(' ', (array) ($query['semantic_hints'] ?? [])));

        if (is_string($plan['intent'] ?? null) && $plan['intent'] !== '') {
            $existingCandidates = array_values(array_filter(
                (array) ($query['intent_candidates'] ?? []),
                fn(array $candidate) => (string) ($candidate['intent'] ?? '') !== $plan['intent']
            ));

            array_unshift($existingCandidates, [
                'intent' => $plan['intent'],
                'score' => 9.0,
                'normalized_score' => 1.0,
                'source' => 'llm_plan',
            ]);
            $query['intent_candidates'] = $existingCandidates;
        }

        $plannedResourceHints = array_map(function ($hint) {
            if (is_string($hint)) {
                return ['resource' => $hint, 'score' => 8.0];
            }

            return [
                'resource' => (string) ($hint['resource'] ?? ''),
                'score' => (float) ($hint['score'] ?? 8.0),
                'projection_ready' => (bool) ($hint['projection_ready'] ?? false),
            ];
        }, array_values(array_filter((array) ($plan['resource_hints'] ?? []), function ($hint) {
            return is_string($hint) || is_array($hint);
        })));

        $query['resource_hints'] = array_values(array_unique(array_merge(
            (array) ($query['resource_hints'] ?? []),
            $plannedResourceHints
        ), SORT_REGULAR));

        $query['projection_hints'] = array_merge(
            (array) ($query['projection_hints'] ?? []),
            array_filter((array) ($plan['projection_hints'] ?? []), fn($value) => $value !== null && $value !== '')
        );
        $query['conversation_hints'] = array_merge(
            (array) ($query['conversation_hints'] ?? []),
            array_filter((array) ($plan['conversation_hints'] ?? []), fn($value) => $value !== null && $value !== '')
        );
        $query['interpretation_confidence'] = max(
            (float) ($query['interpretation_confidence'] ?? 0.0),
            0.72
        );

        return $query;
    }

    private function finalizeAnswer(string $prompt, array $context, array $analysis, string $languageStyle): string
    {
        if ($languageStyle !== 'english') {
            return $this->adaptAnswerStyle($analysis['answer'], $languageStyle);
        }

        return $this->languageModel->formatGroundedResponse($prompt, $context, $analysis)
            ?? $this->adaptAnswerStyle($analysis['answer'], $languageStyle);
    }

    private function adaptAnswerStyle(string $answer, string $style): string
    {
        if ($style === 'english') {
            return $answer;
        }

        $replacements = $style === 'tagalog'
            ? [
                'I counted' => 'Nabilang ko',
                'I compared' => 'Inihambing ko',
                'I built' => 'Nakabuo ako ng',
                'I found' => 'Nakita ko',
                'I matched' => 'Tinugma ko',
                'Here is' => 'Narito ang',
                'I could not' => 'Hindi ko',
                'The current period has' => 'Ang kasalukuyang period ay may',
                'The chatbot can currently use' => 'Kasalukuyang nagagamit ng chatbot ang',
                'Across the matched grounded data sources' => 'Sa mga nagtugmang grounded data sources',
            ]
            : [
                'I counted' => 'Naka-count ako ng',
                'I compared' => 'Kinompare ko',
                'I built' => 'Nakabuo ako ng',
                'I found' => 'Nakita ko',
                'I matched' => 'Na-match ko',
                'Here is' => 'Narito ang',
                'I could not' => 'Hindi ko',
                'The current period has' => 'Ang current period ay may',
                'The chatbot can currently use' => 'Currently nagagamit ng chatbot ang',
                'Across the matched grounded data sources' => 'Across the matched grounded data sources',
            ];

        return str_replace(array_keys($replacements), array_values($replacements), $answer);
    }

    private function localizeProjectionAnswer(string $answer, string $languageStyle): string
    {
        if ($languageStyle === 'english' || trim($answer) === '') {
            return $answer;
        }

        $replacements = [
            'Using ' => 'Gamit ang ',
            ' as the grounded time series, my forecast for ' => ' bilang grounded time series, ang forecast ko para sa ',
            ' is around ' => ' ay humigit-kumulang ',
            ' based on the recent observed trend.' => ' batay sa recent observed trend.',
            ' starts around ' => ' ay nagsisimula sa humigit-kumulang ',
            ' records and trends toward ' => ' records at tumataya papunta sa ',
            ' records across the projected periods.' => ' records sa mga projected period.',
            ' as the traffic proxy, the recent grounded daily baseline is about ' => ' bilang traffic proxy, ang recent grounded daily baseline ay humigit-kumulang ',
            ' For ' => ' Para sa ',
            ', the projected count is around ' => ', ang projected count ay humigit-kumulang ',
            ', with a high scenario of ' => ', at maaaring umabot sa ',
            ' if demand runs above the recent trend.' => ' kung mas mataas ang demand kaysa sa recent trend.',
            ' records.' => ' records.',
            ' records, ' => ' records, ',
            'my scenario projection is ' => 'ang scenario projection ko ay ',
            ' baseline, ' => ' baseline, ',
            ' expected, and up to ' => ' expected, at hanggang ',
            ' high-traffic records.' => ' high-traffic records.',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $answer);
    }
}
