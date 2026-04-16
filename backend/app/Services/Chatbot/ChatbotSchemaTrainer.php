<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Str;

class ChatbotSchemaTrainer
{
    public function buildTrainingProfile(array $profiles, array $history = []): array
    {
        $resources = [];
        $vocabulary = [];
        $examples = [];

        foreach ($profiles as $profile) {
            $resource = $this->resourceBlueprint($profile);
            if ($resource === null) {
                continue;
            }

            $resources[] = $resource;
            array_push($vocabulary, ...$resource['aliases'], ...$resource['columns'], ...$resource['keywords']);
            array_push($examples, ...$resource['examples']);
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'resource_count' => count($resources),
            'resources' => $resources,
            'forecastable_resources' => array_values(array_map(
                fn(array $resource) => $resource['resource'],
                array_filter($resources, fn(array $resource) => (bool) ($resource['projection_ready'] ?? false))
            )),
            'vocabulary' => array_values(array_unique(array_filter(array_map(
                fn(string $term) => $this->normalizeTerm($term),
                $vocabulary
            )))),
            'intent_examples' => array_values(array_unique(array_slice(array_filter($examples), 0, max((int) config('chatbot.training.example_limit', 40), 1)))),
            'history' => $this->historySignals($history),
        ];
    }

    public function mergeTrainingProfiles(array $trainingProfiles, array $profiles = [], array $history = []): array
    {
        $resources = [];
        $vocabulary = [];
        $examples = [];
        $forecastable = [];
        $historySignals = $this->historySignals($history);

        foreach ($trainingProfiles as $trainingProfile) {
            foreach ((array) ($trainingProfile['resources'] ?? []) as $resource) {
                $key = strtolower((string) ($resource['resource'] ?? ''));
                if ($key === '') {
                    continue;
                }

                if (!isset($resources[$key])) {
                    $resources[$key] = $resource;
                } else {
                    $resources[$key]['aliases'] = array_values(array_unique(array_merge(
                        (array) ($resources[$key]['aliases'] ?? []),
                        (array) ($resource['aliases'] ?? [])
                    )));
                    $resources[$key]['columns'] = array_values(array_unique(array_merge(
                        (array) ($resources[$key]['columns'] ?? []),
                        (array) ($resource['columns'] ?? [])
                    )));
                    $resources[$key]['keywords'] = array_values(array_unique(array_merge(
                        (array) ($resources[$key]['keywords'] ?? []),
                        (array) ($resource['keywords'] ?? [])
                    )));
                    $resources[$key]['examples'] = array_values(array_unique(array_merge(
                        (array) ($resources[$key]['examples'] ?? []),
                        (array) ($resource['examples'] ?? [])
                    )));
                    $resources[$key]['projection_ready'] = (bool) (($resources[$key]['projection_ready'] ?? false) || ($resource['projection_ready'] ?? false));
                    $resources[$key]['group_ready'] = (bool) (($resources[$key]['group_ready'] ?? false) || ($resource['group_ready'] ?? false));
                    $resources[$key]['status_ready'] = (bool) (($resources[$key]['status_ready'] ?? false) || ($resource['status_ready'] ?? false));
                }
            }

            array_push($vocabulary, ...(array) ($trainingProfile['vocabulary'] ?? []));
            array_push($examples, ...(array) ($trainingProfile['intent_examples'] ?? []));
            array_push($forecastable, ...(array) ($trainingProfile['forecastable_resources'] ?? []));
        }

        if ($resources === [] && $profiles !== []) {
            return $this->buildTrainingProfile($profiles, $history);
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'resource_count' => count($resources),
            'resources' => array_values($resources),
            'forecastable_resources' => array_values(array_unique(array_filter($forecastable))),
            'vocabulary' => array_values(array_unique(array_filter(array_map(
                fn(string $term) => $this->normalizeTerm($term),
                $vocabulary
            )))),
            'intent_examples' => array_values(array_unique(array_slice(array_filter($examples), 0, max((int) config('chatbot.training.example_limit', 40), 1)))),
            'history' => $historySignals,
        ];
    }

    public function matchPrompt(string $normalized, array $trainingProfile, array $history = []): array
    {
        $normalized = $this->normalizeTerm($normalized);
        if ($normalized === '') {
            return [
                'semantic_hints' => [],
                'resource_hints' => [],
                'conversation_hints' => $this->conversationHints($normalized, $trainingProfile, $history),
                'projection_hints' => $this->projectionHints($normalized, [], $trainingProfile),
            ];
        }

        $resourceHints = [];
        $semanticHints = [];
        $historySignals = $this->historySignals($history);
        $futurePrompt = $this->hasFutureSignal($normalized);

        foreach ((array) ($trainingProfile['resources'] ?? []) as $resource) {
            $aliasMatches = [];
            $score = 0.0;

            foreach ((array) ($resource['aliases'] ?? []) as $alias) {
                $alias = $this->normalizeTerm((string) $alias);
                if ($alias === '') {
                    continue;
                }

                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $normalized) === 1) {
                    $aliasMatches[] = $alias;
                    $score += str_contains($alias, ' ') ? 12.0 : 7.0;
                }
            }

            if ($aliasMatches === [] && ($resource['projection_ready'] ?? false) && $futurePrompt) {
                foreach ((array) ($resource['keywords'] ?? []) as $keyword) {
                    $keyword = $this->normalizeTerm((string) $keyword);
                    if ($keyword === '' || preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $normalized) !== 1) {
                        continue;
                    }

                    $aliasMatches[] = $keyword;
                    $score += 4.0;
                }
            }

            if ($aliasMatches === []) {
                continue;
            }

            if (in_array((string) ($resource['resource'] ?? ''), (array) ($historySignals['recent_resources'] ?? []), true)) {
                $score += 5.0;
            }

            if ($futurePrompt && ($resource['projection_ready'] ?? false)) {
                $score += 6.0;
            }

            $resourceHints[] = [
                'resource' => (string) ($resource['resource'] ?? ''),
                'score' => round($score, 3),
                'projection_ready' => (bool) ($resource['projection_ready'] ?? false),
                'group_ready' => (bool) ($resource['group_ready'] ?? false),
                'status_ready' => (bool) ($resource['status_ready'] ?? false),
                'matched_terms' => array_values(array_unique($aliasMatches)),
            ];

            $semanticHints[] = (string) ($resource['resource'] ?? '');
            array_push($semanticHints, ...array_slice($aliasMatches, 0, 4));
        }

        usort($resourceHints, fn(array $left, array $right) => (float) ($right['score'] ?? 0.0) <=> (float) ($left['score'] ?? 0.0));

        return [
            'semantic_hints' => array_values(array_unique(array_filter($semanticHints))),
            'resource_hints' => array_slice($resourceHints, 0, 6),
            'conversation_hints' => $this->conversationHints($normalized, $trainingProfile, $history),
            'projection_hints' => $this->projectionHints($normalized, $resourceHints, $trainingProfile),
        ];
    }

    private function resourceBlueprint(array $profile): ?array
    {
        $resource = trim((string) ($profile['resource'] ?? ''));
        if ($resource === '') {
            return null;
        }

        $humanized = $this->normalizeTerm(str_replace(['_', '-'], ' ', $resource));
        $aliases = array_merge(
            [$resource, $humanized, Str::singular($humanized), Str::plural($humanized)],
            (array) ($profile['semantic_terms'] ?? []),
            $this->columnTerms((array) ($profile['columns'] ?? [])),
            $this->labelTerms((array) ($profile['top_groups'] ?? [])),
            $this->sampleValueTerms((array) ($profile['sample_rows'] ?? []))
        );

        $aliases = array_values(array_unique(array_filter(array_map(
            fn(string $term) => $this->normalizeTerm($term),
            $aliases
        ))));

        $columns = array_values(array_unique(array_filter(array_map(
            fn($column) => $this->normalizeTerm((string) (is_array($column) ? ($column['name'] ?? '') : $column)),
            (array) ($profile['column_names'] ?? [])
        ))));

        $keywords = array_values(array_unique(array_filter(array_merge(
            $aliases,
            $columns
        ))));

        $projectionReady = !empty($profile['detected']['date_column']);
        $groupReady = !empty($profile['detected']['group_column']);
        $statusReady = collect((array) ($profile['column_names'] ?? []))
            ->contains(fn(string $name) => preg_match('/status|state|approval|enrollment|reviewed_by|reviewer/i', $name) === 1);

        return [
            'resource' => $resource,
            'aliases' => $aliases,
            'columns' => $columns,
            'keywords' => $keywords,
            'projection_ready' => $projectionReady,
            'group_ready' => $groupReady,
            'status_ready' => $statusReady,
            'examples' => $this->examplesForResource($resource, $humanized, $projectionReady, $groupReady),
        ];
    }

    private function examplesForResource(string $resource, string $humanized, bool $projectionReady, bool $groupReady): array
    {
        $examples = [
            sprintf('How many %s are there?', $humanized),
            sprintf('Summarize %s.', $humanized),
        ];

        if ($groupReady) {
            $examples[] = sprintf('What are the top categories in %s?', $resource);
        }

        if ($projectionReady) {
            $examples[] = sprintf('Show monthly trend for %s.', $resource);
            $examples[] = sprintf('Forecast next month for %s.', $resource);
            $examples[] = sprintf('Project %s for the next period.', $resource);
        }

        return $examples;
    }

    private function columnTerms(array $columns): array
    {
        $terms = [];

        foreach ($columns as $column) {
            $name = trim((string) ($column['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $terms[] = $name;
            $terms[] = str_replace(['_', '-'], ' ', $name);
        }

        return $terms;
    }

    private function labelTerms(array $topGroups): array
    {
        $terms = [];

        foreach ($topGroups as $group) {
            $label = trim((string) ($group['label'] ?? ''));
            if ($label !== '' && preg_match('/[a-z]/i', $label) === 1) {
                $terms[] = $label;
            }
        }

        return $terms;
    }

    private function sampleValueTerms(array $sampleRows): array
    {
        $terms = [];

        foreach (array_slice($sampleRows, 0, 3) as $row) {
            foreach ((array) $row as $value) {
                if (!is_string($value)) {
                    continue;
                }

                $value = trim($value);
                if ($value === '' || strlen($value) > 32 || preg_match('/^\d+$/', $value) === 1) {
                    continue;
                }

                $terms[] = $value;
            }
        }

        return $terms;
    }

    private function historySignals(array $history): array
    {
        $recentResources = [];
        $recentIntents = [];

        for ($index = count($history) - 1; $index >= 0; $index--) {
            $message = $history[$index] ?? null;
            if (!is_array($message) || ($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            foreach ((array) ($message['sources'] ?? []) as $source) {
                $resource = (string) ($source['resource'] ?? '');
                if ($resource !== '') {
                    $recentResources[] = $resource;
                }
            }

            $intent = (string) ($message['intent'] ?? '');
            if ($intent !== '') {
                $recentIntents[] = $intent;
            }

            if (count($recentResources) >= 3 && count($recentIntents) >= 3) {
                break;
            }
        }

        return [
            'recent_resources' => array_values(array_unique(array_slice($recentResources, 0, 3))),
            'recent_intents' => array_values(array_unique(array_slice($recentIntents, 0, 3))),
        ];
    }

    private function conversationHints(string $normalized, array $trainingProfile, array $history): array
    {
        $historySignals = $trainingProfile['history'] ?? $this->historySignals($history);
        $tokens = array_values(array_filter(explode(' ', $normalized), fn(string $token) => $token !== ''));
        $followUpLike = preg_match('/\b(what about|how about|paano naman|same|related|also|then|next|following|sunod|susunod)\b/', $normalized) === 1
            || (count($tokens) <= 4 && (($historySignals['recent_resources'] ?? []) !== []));

        return [
            'follow_up_like' => $followUpLike,
            'wants_related' => preg_match('/\b(related|similar|same source|same database|also|what about|how about|paano naman)\b/', $normalized) === 1,
            'recent_resources' => (array) ($historySignals['recent_resources'] ?? []),
            'recent_intents' => (array) ($historySignals['recent_intents'] ?? []),
        ];
    }

    private function projectionHints(string $normalized, array $resourceHints, array $trainingProfile): array
    {
        $mode = null;

        if (preg_match('/\b(forecast|next|upcoming|susunod|future|next month|next week|next quarter|next year)\b/', $normalized) === 1) {
            $mode = 'forecast';
        } elseif (preg_match('/\b(projection|project|predict|estimate|expected|inaasahan|inaasahang)\b/', $normalized) === 1) {
            $mode = 'scenario_projection';
        }

        $resourceCandidates = array_values(array_map(
            fn(array $hint) => (string) ($hint['resource'] ?? ''),
            array_filter($resourceHints, fn(array $hint) => (bool) ($hint['projection_ready'] ?? false))
        ));

        if ($resourceCandidates === []) {
            $resourceCandidates = (array) ($trainingProfile['forecastable_resources'] ?? []);
        }

        return [
            'mode' => $mode,
            'future_like' => $this->hasFutureSignal($normalized),
            'resource_candidates' => array_values(array_unique(array_filter($resourceCandidates))),
            'granularity' => match (true) {
                preg_match('/\b(week|weekly|linggo)\b/', $normalized) === 1 => 'weekly',
                preg_match('/\b(month|monthly|buwan|quarter|year|taon)\b/', $normalized) === 1 => 'monthly',
                default => 'daily',
            },
        ];
    }

    private function hasFutureSignal(string $normalized): bool
    {
        return preg_match('/\b(forecast|future|next|upcoming|tomorrow|susunod|expected|inaasahan|inaasahang|project|projection|predict|estimate)\b/', $normalized) === 1;
    }

    private function normalizeTerm(string $term): string
    {
        $term = Str::ascii(Str::lower(trim($term)));
        $term = preg_replace('/[^a-z0-9\s_-]+/', ' ', $term) ?? '';
        $term = str_replace(['_', '-'], ' ', $term);
        $term = preg_replace('/\s+/', ' ', $term) ?? '';

        return trim($term);
    }
}
