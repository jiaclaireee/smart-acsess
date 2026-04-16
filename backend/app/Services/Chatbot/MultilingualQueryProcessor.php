<?php

namespace App\Services\Chatbot;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class MultilingualQueryProcessor
{
    public function analyze(string $prompt): array
    {
        $normalized = $this->normalize($prompt);
        $languageStyle = $this->detectLanguageStyle($normalized);
        $semanticHints = $this->semanticHints($normalized);

        return [
            'prompt' => $prompt,
            'normalized' => $normalized,
            'language_style' => $languageStyle,
            'semantic_text' => trim($normalized . ' ' . implode(' ', $semanticHints)),
            'semantic_hints' => $semanticHints,
            'date_scope' => $this->dateScope($normalized),
            'comparison_scope' => $this->comparisonScope($normalized),
            'prefers_simple' => preg_match('/\b(simple|simply|madali|simple explanation|ipaliwanag|paliwanag)\b/', $normalized) === 1,
        ];
    }

    public function render(string $style, string $english, ?string $tagalog = null, ?string $taglish = null): string
    {
        return match ($style) {
            'tagalog' => $tagalog ?? $taglish ?? $english,
            'taglish' => $taglish ?? $tagalog ?? $english,
            default => $english,
        };
    }

    public function dateScopeLabel(?array $scope): ?string
    {
        if (!is_array($scope)) {
            return null;
        }

        return match ($scope['key'] ?? null) {
            'today' => 'today',
            'current_week' => 'this week',
            'current_month' => 'this month',
            'current_quarter' => 'this quarter',
            'current_year' => 'this year',
            default => null,
        };
    }

    private function normalize(string $prompt): string
    {
        $prompt = Str::ascii(Str::lower($prompt));
        $prompt = preg_replace('/\bhow may\b/', 'how many', $prompt) ?? $prompt;
        $prompt = preg_replace('/[^a-z0-9\s]+/', ' ', $prompt) ?? '';
        $prompt = preg_replace('/\s+/', ' ', $prompt) ?? '';

        return trim($prompt);
    }

    private function detectLanguageStyle(string $normalized): string
    {
        $tagalogMarkers = [
            'ilan', 'ano', 'kamusta', 'natin', 'ngayong', 'itong', 'may', 'ba', 'pakita',
            'ipakita', 'buod', 'paliwanag', 'taon', 'buwan', 'linggo', 'quarter', 'lumago',
            'sasakyan', 'plaka', 'bilang', 'gaano', 'karami', 'na', 'ang', 'mga', 'jeep',
        ];
        $englishMarkers = [
            'what', 'show', 'summary', 'explain', 'trend', 'growth', 'records', 'data',
            'month', 'quarter', 'year', 'top', 'count', 'entry', 'entries', 'logged',
        ];

        $tagalogCount = $this->markerCount($normalized, $tagalogMarkers);
        $englishCount = $this->markerCount($normalized, $englishMarkers);

        if ($tagalogCount >= 2 && $englishCount === 0) {
            return 'tagalog';
        }

        if ($tagalogCount >= 2 && $englishCount >= 1) {
            return 'taglish';
        }

        if ($englishCount === 0 && preg_match('/^(ilan|ano|gaano karami)\b/', $normalized) === 1) {
            return 'tagalog';
        }

        return 'english';
    }

    private function semanticHints(string $normalized): array
    {
        $dictionary = [
            'ilan' => ['count', 'total'],
            'ilan ang' => ['count', 'total'],
            'gaano karami' => ['count', 'total'],
            'kabuuan' => ['total', 'summary'],
            'buod' => ['summary', 'overview'],
            'paliwanag' => ['summary', 'explain'],
            'ipaliwanag' => ['summary', 'explain'],
            'pakita' => ['show', 'preview'],
            'ipakita' => ['show', 'preview'],
            'ngayong buwan' => ['current_month'],
            'itong buwan' => ['current_month'],
            'this month' => ['current_month'],
            'ngayong quarter' => ['current_quarter'],
            'quarter na ito' => ['current_quarter'],
            'this quarter' => ['current_quarter'],
            'ngayong taon' => ['current_year'],
            'this year' => ['current_year'],
            'trend' => ['trend', 'over_time'],
            'galaw' => ['trend', 'over_time'],
            'takbo' => ['trend', 'over_time'],
            'growth' => ['growth', 'compare'],
            'lumago' => ['growth', 'compare'],
            'tumaas' => ['growth', 'compare'],
            'dumami' => ['growth', 'compare'],
            'top' => ['top_categories', 'breakdown'],
            'pinakamarami' => ['top_categories', 'breakdown'],
            'pinaka madami' => ['top_categories', 'breakdown'],
            'categories' => ['top_categories', 'breakdown'],
            'available data' => ['summary', 'available', 'data'],
            'entry' => ['entry', 'entries', 'count'],
            'entries' => ['entry', 'entries', 'count'],
            'logged' => ['log', 'entry', 'count'],
            'log' => ['log', 'entry'],
            'gate' => ['gate', 'location'],
            'gates' => ['gate', 'location'],
            'location' => ['location'],
            'sasakyan' => ['vehicle', 'vehicles'],
            'plaka' => ['license_plate', 'license_number'],
            'traffic' => ['traffic', 'projection'],
            'volume' => ['volume', 'projection'],
            'expected' => ['projection', 'expected'],
            'forecast' => ['projection', 'forecast', 'future'],
            'predict' => ['projection', 'forecast', 'future'],
            'next month' => ['projection', 'forecast', 'future', 'monthly'],
            'next week' => ['projection', 'forecast', 'future', 'weekly'],
            'next quarter' => ['projection', 'forecast', 'future', 'monthly'],
            'next year' => ['projection', 'forecast', 'future', 'monthly'],
            'inaasahan' => ['projection', 'expected'],
            'inaasahang' => ['projection', 'expected'],
            'susunod na buwan' => ['projection', 'forecast', 'future', 'monthly'],
            'susunod na linggo' => ['projection', 'forecast', 'future', 'weekly'],
            'campus' => ['campus'],
        ];

        $hints = [];

        foreach ($dictionary as $phrase => $mappedHints) {
            if (str_contains($normalized, $phrase)) {
                array_push($hints, ...$mappedHints);
            }
        }

        return array_values(array_unique($hints));
    }

    private function dateScope(string $normalized): ?array
    {
        $now = CarbonImmutable::now();

        return match (true) {
            preg_match('/\b(today|ngayon)\b/', $normalized) === 1 => [
                'key' => 'today',
                'from' => $now->startOfDay()->toDateString(),
                'to' => $now->endOfDay()->toDateString(),
            ],
            preg_match('/\b(this week|ngayong linggo|itong linggo)\b/', $normalized) === 1 => [
                'key' => 'current_week',
                'from' => $now->startOfWeek()->toDateString(),
                'to' => $now->endOfWeek()->toDateString(),
            ],
            preg_match('/\b(this month|ngayong buwan|itong buwan|current month)\b/', $normalized) === 1 => [
                'key' => 'current_month',
                'from' => $now->startOfMonth()->toDateString(),
                'to' => $now->endOfMonth()->toDateString(),
            ],
            preg_match('/\b(this quarter|ngayong quarter|quarter na ito|current quarter)\b/', $normalized) === 1 => [
                'key' => 'current_quarter',
                'from' => $now->firstOfQuarter()->toDateString(),
                'to' => $now->lastOfQuarter()->toDateString(),
            ],
            preg_match('/\b(this year|ngayong taon|current year)\b/', $normalized) === 1 => [
                'key' => 'current_year',
                'from' => $now->startOfYear()->toDateString(),
                'to' => $now->endOfYear()->toDateString(),
            ],
            default => null,
        };
    }

    private function comparisonScope(string $normalized): ?array
    {
        if (preg_match('/\b(growth|increase|decrease|lumago|tumaas|bumaba|may growth)\b/', $normalized) !== 1) {
            return null;
        }

        $scope = $this->dateScope($normalized);
        if ($scope === null) {
            return [
                'label' => 'current month versus the previous month',
                'current' => $this->scopeRange('month', 0),
                'previous' => $this->scopeRange('month', -1),
            ];
        }

        return match ($scope['key']) {
            'current_week' => [
                'label' => 'this week versus last week',
                'current' => $scope,
                'previous' => $this->scopeRange('week', -1),
            ],
            'current_quarter' => [
                'label' => 'this quarter versus last quarter',
                'current' => $scope,
                'previous' => $this->scopeRange('quarter', -1),
            ],
            'current_year' => [
                'label' => 'this year versus last year',
                'current' => $scope,
                'previous' => $this->scopeRange('year', -1),
            ],
            default => [
                'label' => 'this month versus last month',
                'current' => $scope,
                'previous' => $this->scopeRange('month', -1),
            ],
        };
    }

    private function scopeRange(string $unit, int $offset): array
    {
        $now = CarbonImmutable::now();
        $point = match ($unit) {
            'week' => $now->addWeeks($offset),
            'quarter' => $now->addQuarters($offset),
            'year' => $now->addYears($offset),
            default => $now->addMonths($offset),
        };

        return match ($unit) {
            'week' => [
                'key' => $offset === 0 ? 'current_week' : 'previous_week',
                'from' => $point->startOfWeek()->toDateString(),
                'to' => $point->endOfWeek()->toDateString(),
            ],
            'quarter' => [
                'key' => $offset === 0 ? 'current_quarter' : 'previous_quarter',
                'from' => $point->firstOfQuarter()->toDateString(),
                'to' => $point->lastOfQuarter()->toDateString(),
            ],
            'year' => [
                'key' => $offset === 0 ? 'current_year' : 'previous_year',
                'from' => $point->startOfYear()->toDateString(),
                'to' => $point->endOfYear()->toDateString(),
            ],
            default => [
                'key' => $offset === 0 ? 'current_month' : 'previous_month',
                'from' => $point->startOfMonth()->toDateString(),
                'to' => $point->endOfMonth()->toDateString(),
            ],
        };
    }

    private function markerCount(string $normalized, array $markers): int
    {
        $count = 0;

        foreach ($markers as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/', $normalized) === 1) {
                $count++;
            }
        }

        return $count;
    }
}
