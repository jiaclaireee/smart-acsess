<?php

namespace App\Services\Chatbot;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class OfficialUniversityKnowledgeService
{
    public function search(string $prompt): array
    {
        if (!config('chatbot.external_knowledge.enabled', true)) {
            return [
                'matches' => [],
                'facts' => [],
                'warnings' => [],
                'date' => null,
            ];
        }

        $normalizedPrompt = $this->normalize($prompt);
        $requestedDate = $this->extractDate($prompt);
        $matches = [];

        foreach ((array) config('chatbot.external_knowledge.references', []) as $reference) {
            $score = $this->scoreReference($normalizedPrompt, $reference);

            if ($score <= 0) {
                continue;
            }

            $reference['score'] = $score;
            $matches[] = $reference;
        }

        usort($matches, fn(array $left, array $right) => ($right['score'] ?? 0) <=> ($left['score'] ?? 0));
        $matches = array_slice($matches, 0, 3);

        return [
            'matches' => $matches,
            'facts' => $this->formatFacts($matches),
            'warnings' => $this->buildWarnings($requestedDate, $matches),
            'date' => $requestedDate?->toDateString(),
        ];
    }

    public function shouldAnswerDirectly(string $prompt): bool
    {
        $normalized = $this->normalize($prompt);

        if (!$this->isUniversityPrompt($normalized)) {
            return false;
        }

        return preg_match('/\b(what|who|where|when|tell me|about|official|campus|campuses|constituent|university|commencement|graduation|vision|mission|contact)\b/', $normalized) === 1;
    }

    public function summarizeForAnswer(string $prompt, array $search): ?string
    {
        $matches = $search['matches'] ?? [];
        if ($matches === []) {
            return null;
        }

        $lead = $matches[0];
        $parts = [
            (string) ($lead['summary'] ?? ''),
        ];

        if (count($matches) > 1) {
            $parts[] = sprintf(
                'Relevant official references also include %s.',
                implode(', ', array_map(fn(array $match) => (string) ($match['title'] ?? 'official UP source'), array_slice($matches, 1, 2)))
            );
        }

        return trim(implode(' ', array_filter($parts)));
    }

    public function mentionsMajorCampusEvent(string $prompt, array $search): bool
    {
        $normalized = $this->normalize($prompt);

        if (preg_match('/\b(commencement|graduation|ceremony|event)\b/', $normalized) === 1) {
            return true;
        }

        foreach (($search['matches'] ?? []) as $match) {
            if (in_array('event', (array) ($match['tags'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    public function isUniversityPrompt(string $normalizedPrompt): bool
    {
        return preg_match('/\b(university of the philippines|up system|uplb|up los banos|up diliman|up manila|up visayas|up cebu|up baguio|up mindanao|up open university|philippines)\b/', $normalizedPrompt) === 1;
    }

    private function scoreReference(string $normalizedPrompt, array $reference): int
    {
        $score = 0;

        foreach ((array) ($reference['keywords'] ?? []) as $keyword) {
            $normalizedKeyword = $this->normalize((string) $keyword);
            if ($normalizedKeyword !== '' && preg_match('/\b' . preg_quote($normalizedKeyword, '/') . '\b/', $normalizedPrompt) === 1) {
                $score += 8;
            }
        }

        $score += $this->overlapScore($normalizedPrompt, (string) ($reference['title'] ?? '')) * 3;
        $score += $this->overlapScore($normalizedPrompt, (string) ($reference['summary'] ?? '')) * 2;

        return $score;
    }

    private function formatFacts(array $matches): array
    {
        return array_map(function (array $match) {
            return trim(sprintf(
                '%s: %s Source: %s',
                (string) ($match['title'] ?? 'Official UP source'),
                (string) ($match['summary'] ?? ''),
                (string) ($match['url'] ?? '')
            ));
        }, $matches);
    }

    private function buildWarnings(?CarbonImmutable $requestedDate, array $matches): array
    {
        if ($requestedDate === null) {
            return [];
        }

        foreach ($matches as $match) {
            foreach ((array) ($match['known_dates'] ?? []) as $knownDate) {
                if ($knownDate === $requestedDate->toDateString()) {
                    return [];
                }
            }
        }

        return [
            sprintf(
                'The configured official UP references do not confirm an event on %s, so any event-specific projection uses the selected database plus scenario assumptions rather than a confirmed  official schedule.',
                $requestedDate->format('F j, Y')
            ),
        ];
    }

    private function extractDate(string $prompt): ?CarbonImmutable
    {
        $patterns = [
            '/\b(\d{4}-\d{2}-\d{2})\b/',
            '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\b/i',
            '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            try {
                return CarbonImmutable::parse($matches[0], 'Asia/Manila');
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function overlapScore(string $normalizedPrompt, string $value): int
    {
        $left = array_unique($this->tokens($normalizedPrompt));
        $right = array_unique($this->tokens($value));

        if ($left === [] || $right === []) {
            return 0;
        }

        return count(array_intersect($left, $right));
    }

    private function tokens(string $value): array
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalized), fn(string $token) => $token !== ''));
    }

    private function normalize(string $value): string
    {
        $value = Str::lower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim($value);
    }
}
