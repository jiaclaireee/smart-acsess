<?php

namespace App\Services\Chatbot;

use App\Services\Database\Contracts\DatabaseConnector;
use Carbon\CarbonImmutable;

class ProjectionService
{
    public function buildProjection(
        DatabaseConnector $connector,
        array $context,
        string $resource,
        string $dateColumn,
        string $prompt,
        array $externalKnowledge,
    ): array {
        $request = $this->projectionRequest($prompt);
        $requestedDate = $request['requested_date'];
        $latestObservedDate = $this->latestObservedDate($connector, $resource, $dateColumn);

        if ($latestObservedDate === null) {
            return $this->insufficientProjection(
                $resource,
                $dateColumn,
                'I could not find a recent timestamp in the selected database resource, so I cannot build a grounded projection from it.',
                $externalKnowledge,
            );
        }

        $lookbackDays = max((int) config('chatbot.projection.lookback_days', 90), 14);
        $fromDate = $latestObservedDate->subDays($lookbackDays - 1)->toDateString();
        $seriesPeriod = ($request['mode'] ?? 'scenario_projection') === 'forecast'
            ? (($request['granularity'] ?? 'daily') === 'monthly' ? 'monthly' : 'daily')
            : 'daily';
        $seriesPoints = $connector->aggregateByDate(
            $resource,
            $dateColumn,
            'count',
            null,
            [
                'from' => $fromDate,
                'to' => $latestObservedDate->toDateString(),
            ],
            $seriesPeriod,
            max((int) config('chatbot.projection.daily_limit', 120), 30)
        );

        if ($seriesPoints === []) {
            return $this->insufficientProjection(
                $resource,
                $dateColumn,
                sprintf('I could not derive any daily trend points from %s.%s, so I cannot project safely.', $resource, $dateColumn),
                $externalKnowledge,
            );
        }

        if (($request['mode'] ?? 'scenario_projection') === 'forecast') {
            return $this->buildForecastProjection(
                $resource,
                $dateColumn,
                $latestObservedDate,
                $seriesPoints,
                $request,
                $externalKnowledge,
            );
        }

        $values = array_values(array_map(fn(array $point) => (float) ($point['value'] ?? 0), $seriesPoints));
        $recentWindow = array_slice($values, -min(count($values), 14));
        $baseline = (int) round(array_sum($recentWindow) / max(count($recentWindow), 1));
        $slope = $this->linearSlope($values);
        $daysAhead = $requestedDate?->startOfDay()->diffInDays($latestObservedDate->startOfDay(), false);
        $daysAhead = is_int($daysAhead) ? max($daysAhead, 0) : 0;

        $trendAdjustedBaseline = max((int) round($baseline + ($slope * min($daysAhead, 45))), 0);
        $eventAware = $externalKnowledge['event_related'] ?? false;
        $majorEvent = $externalKnowledge['major_event'] ?? false;

        $expectedMultiplier = $majorEvent
            ? (float) config('chatbot.projection.major_event_expected_multiplier', 1.2)
            : (float) config('chatbot.projection.expected_multiplier', 1.05);
        $highMultiplier = $majorEvent
            ? (float) config('chatbot.projection.major_event_high_multiplier', 1.35)
            : (float) config('chatbot.projection.high_multiplier', 1.12);

        $baselineScenario = $trendAdjustedBaseline;
        $expectedScenario = max((int) round($trendAdjustedBaseline * $expectedMultiplier), $baselineScenario);
        $highScenario = max((int) round($trendAdjustedBaseline * $highMultiplier), $expectedScenario);

        $eventLabel = $requestedDate?->format('F j, Y') ?? 'the requested period';
        $proxyLabel = sprintf('%s.%s', $resource, $dateColumn);
        $externalWarnings = (array) ($externalKnowledge['warnings'] ?? []);
        $externalFacts = (array) ($externalKnowledge['facts'] ?? []);

        $answer = $majorEvent
            ? sprintf(
                'Using %s as the traffic proxy, the recent grounded daily baseline is about %d records. For a UPLB commencement-type event on %s, my scenario projection is %d baseline, %d expected, and up to %d high-traffic records.',
                $proxyLabel,
                $baselineScenario,
                $eventLabel,
                $baselineScenario,
                $expectedScenario,
                $highScenario
            )
            : sprintf(
                'Using %s as the traffic proxy, the recent grounded daily baseline is about %d records. For %s, the projected count is around %d records, with a high scenario of %d if demand runs above the recent trend.',
                $proxyLabel,
                $baselineScenario,
                $eventLabel,
                $expectedScenario,
                $highScenario
            );

        $facts = array_merge([
            sprintf('Projection resource: %s', $resource),
            sprintf('Projection date column: %s', $dateColumn),
            sprintf('Latest observed date: %s', $latestObservedDate->toDateString()),
            sprintf('Recent daily baseline: %d', $baselineScenario),
            sprintf('Expected scenario: %d', $expectedScenario),
            sprintf('High scenario: %d', $highScenario),
        ], array_slice($externalFacts, 0, 2));

        $warnings = array_values(array_unique(array_merge($externalWarnings, [
            $majorEvent
                ? 'The uplift above the baseline is scenario-based because the selected database does not label historical commencement days explicitly.'
                : 'This projection is based on observed database trends and does not guarantee actual event turnout or traffic volume.',
        ])));

        return [
            'intent' => 'projection',
            'grounded' => true,
            'insufficient_data' => false,
            'answer' => $answer,
            'facts' => $facts,
            'warnings' => $warnings,
            'suggestions' => [
                sprintf('Show daily trend for %s.', $resource),
                sprintf('Summarize %s.', $resource),
                'List the official UP sources used for this answer.',
            ],
            'table' => [
                'columns' => ['scenario', 'projected_count'],
                'rows' => [
                    ['scenario' => 'Baseline', 'projected_count' => $baselineScenario],
                    ['scenario' => 'Expected', 'projected_count' => $expectedScenario],
                    ['scenario' => 'High', 'projected_count' => $highScenario],
                ],
            ],
            'chart' => [
                'type' => 'bar',
                'title' => sprintf('Projection scenarios for %s', $eventLabel),
                'labels' => ['Baseline', 'Expected', 'High'],
                'series' => [$baselineScenario, $expectedScenario, $highScenario],
            ],
        ];
    }

    private function buildForecastProjection(
        string $resource,
        string $dateColumn,
        CarbonImmutable $latestObservedDate,
        array $seriesPoints,
        array $request,
        array $externalKnowledge,
    ): array {
        $values = array_values(array_map(fn(array $point) => (float) ($point['value'] ?? 0), $seriesPoints));
        if (count($values) < 2) {
            return $this->insufficientProjection(
                $resource,
                $dateColumn,
                sprintf('I need at least two grounded trend points from %s.%s before I can forecast from it.', $resource, $dateColumn),
                $externalKnowledge,
            );
        }

        $granularity = (string) ($request['granularity'] ?? 'daily');
        $horizon = max((int) ($request['horizon'] ?? 1), 1);
        $recentWindowSize = $granularity === 'monthly' ? min(count($values), 4) : min(count($values), 14);
        $recentWindow = array_slice($values, -$recentWindowSize);
        $baseline = (float) array_sum($recentWindow) / max(count($recentWindow), 1);
        $lastObserved = (float) end($values);
        $slope = $this->linearSlope($values);
        $rows = [];
        $series = [];

        for ($step = 1; $step <= $horizon; $step++) {
            $projected = max((int) round(($baseline * 0.7) + (($lastObserved + ($slope * $step)) * 0.3)), 0);
            $periodDate = $this->advanceForecastDate($latestObservedDate, $granularity, $step);
            $label = $this->formatForecastLabel($periodDate, $granularity);
            $rows[] = [
                'forecast_period' => $label,
                'projected_count' => $projected,
            ];
            $series[] = $projected;
        }

        $firstProjection = (int) ($rows[0]['projected_count'] ?? 0);
        $lastProjection = (int) ($rows[count($rows) - 1]['projected_count'] ?? $firstProjection);
        $horizonLabel = (string) ($request['label'] ?? 'the next period');
        $proxyLabel = sprintf('%s.%s', $resource, $dateColumn);

        $answer = $horizon === 1
            ? sprintf(
                'Using %s as the grounded time series, my forecast for %s is around %d records based on the recent observed trend.',
                $proxyLabel,
                $horizonLabel,
                $firstProjection
            )
            : sprintf(
                'Using %s as the grounded time series, my forecast for %s starts around %d records and trends toward %d records across the projected periods.',
                $proxyLabel,
                $horizonLabel,
                $firstProjection,
                $lastProjection
            );

        $facts = array_merge([
            sprintf('Projection resource: %s', $resource),
            sprintf('Projection date column: %s', $dateColumn),
            sprintf('Latest observed date: %s', $latestObservedDate->toDateString()),
            sprintf('Forecast horizon: %s', $horizonLabel),
            sprintf('Forecast granularity: %s', $granularity),
        ], array_slice((array) ($externalKnowledge['facts'] ?? []), 0, 2));

        $warnings = array_values(array_unique(array_merge(
            ['This forecast is trend-based and should be treated as a grounded estimate, not a guaranteed outcome.'],
            (array) ($externalKnowledge['warnings'] ?? [])
        )));

        return [
            'intent' => 'projection',
            'grounded' => true,
            'insufficient_data' => false,
            'answer' => $answer,
            'facts' => $facts,
            'warnings' => $warnings,
            'suggestions' => [
                sprintf('Show monthly trend for %s.', $resource),
                sprintf('Summarize %s.', $resource),
                sprintf('Preview %s.', $resource),
            ],
            'table' => [
                'columns' => ['forecast_period', 'projected_count'],
                'rows' => $rows,
            ],
            'chart' => [
                'type' => 'line',
                'title' => sprintf('Forecast for %s', $horizonLabel),
                'labels' => array_column($rows, 'forecast_period'),
                'series' => $series,
            ],
        ];
    }

    private function latestObservedDate(DatabaseConnector $connector, string $resource, string $dateColumn): ?CarbonImmutable
    {
        $rows = $connector->previewRows($resource, [
            'sort_by' => $dateColumn,
            'sort_direction' => 'desc',
        ], 1);

        $value = $rows[0][$dateColumn] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Asia/Manila');
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractRequestedDate(string $prompt): ?CarbonImmutable
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

    private function projectionRequest(string $prompt): array
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9\s]+/', ' ', $prompt));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
        $requestedDate = $this->extractRequestedDate($prompt);
        $mode = preg_match('/\b(forecast|future|next|upcoming|susunod)\b/', $normalized) === 1
            ? 'forecast'
            : 'scenario_projection';
        $granularity = match (true) {
            preg_match('/\b(week|weekly)\b/', $normalized) === 1 => 'weekly',
            preg_match('/\b(month|monthly|quarter|year)\b/', $normalized) === 1 => 'monthly',
            default => 'daily',
        };
        $horizon = 1;
        $label = 'the next period';

        if (preg_match('/\bnext\s+(\d+)\s+(day|days|week|weeks|month|months|quarter|quarters|year|years)\b/', $normalized, $matches) === 1) {
            $horizon = max((int) ($matches[1] ?? 1), 1);
            $unit = (string) ($matches[2] ?? 'period');
            $granularity = in_array($unit, ['month', 'months', 'quarter', 'quarters', 'year', 'years'], true) ? 'monthly' : (in_array($unit, ['week', 'weeks'], true) ? 'weekly' : 'daily');
            $label = sprintf('the next %d %s', $horizon, $unit);
        } elseif (preg_match('/\bnext month\b/', $normalized) === 1) {
            $granularity = 'monthly';
            $label = 'next month';
        } elseif (preg_match('/\bnext quarter\b/', $normalized) === 1) {
            $granularity = 'monthly';
            $horizon = 3;
            $label = 'next quarter';
        } elseif (preg_match('/\bnext year\b/', $normalized) === 1) {
            $granularity = 'monthly';
            $horizon = 12;
            $label = 'next year';
        } elseif (preg_match('/\bnext week\b/', $normalized) === 1) {
            $granularity = 'weekly';
            $label = 'next week';
        } elseif (preg_match('/\btomorrow\b/', $normalized) === 1) {
            $granularity = 'daily';
            $label = 'tomorrow';
        } elseif ($requestedDate !== null) {
            $label = $requestedDate->format('F j, Y');
        }

        return [
            'mode' => $mode,
            'granularity' => $granularity,
            'horizon' => $horizon,
            'label' => $label,
            'requested_date' => $requestedDate,
        ];
    }

    private function linearSlope(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $xMean = ($count - 1) / 2;
        $yMean = array_sum($values) / $count;
        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($values as $index => $value) {
            $xDiff = $index - $xMean;
            $yDiff = $value - $yMean;
            $numerator += $xDiff * $yDiff;
            $denominator += $xDiff * $xDiff;
        }

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    private function advanceForecastDate(CarbonImmutable $date, string $granularity, int $step): CarbonImmutable
    {
        return match ($granularity) {
            'monthly' => $date->addMonths($step),
            'weekly' => $date->addWeeks($step),
            default => $date->addDays($step),
        };
    }

    private function formatForecastLabel(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            'monthly' => $date->format('Y-m'),
            'weekly' => sprintf('Week of %s', $date->startOfWeek()->format('Y-m-d')),
            default => $date->format('Y-m-d'),
        };
    }

    private function insufficientProjection(
        string $resource,
        string $dateColumn,
        string $message,
        array $externalKnowledge,
    ): array {
        return [
            'intent' => 'projection',
            'grounded' => false,
            'insufficient_data' => true,
            'answer' => $message,
            'facts' => array_merge([
                sprintf('Projection resource: %s', $resource),
                sprintf('Projection date column: %s', $dateColumn),
            ], array_slice((array) ($externalKnowledge['facts'] ?? []), 0, 2)),
            'warnings' => array_values(array_unique(array_merge(
                ['Projection could not be computed from the available structured trend data.'],
                (array) ($externalKnowledge['warnings'] ?? [])
            ))),
            'suggestions' => [
                sprintf('Show daily trend for %s.', $resource),
                sprintf('Preview %s.', $resource),
            ],
            'table' => null,
            'chart' => null,
        ];
    }
}
