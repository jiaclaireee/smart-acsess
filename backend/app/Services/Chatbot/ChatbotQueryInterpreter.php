<?php

namespace App\Services\Chatbot;

class ChatbotQueryInterpreter
{
    public function __construct(
        protected MultilingualQueryProcessor $queryProcessor,
        protected ChatbotSchemaTrainer $schemaTrainer,
    )
    {
    }

    public function interpret(string $prompt, array $trainingProfile = [], array $history = []): QueryInterpretation
    {
        $base = $this->queryProcessor->analyze($prompt);
        $normalized = (string) ($base['normalized'] ?? '');
        $signals = $this->domainSignals($normalized);
        $trainedProfile = $trainingProfile !== []
            ? $trainingProfile
            : $this->schemaTrainer->buildTrainingProfile([], $history);
        $trainingSignals = $this->schemaTrainer->matchPrompt($normalized, $trainedProfile, $history);
        $semanticHints = array_values(array_unique(array_merge(
            (array) ($base['semantic_hints'] ?? []),
            (array) ($signals['semantic_hints'] ?? []),
            (array) ($trainingSignals['semantic_hints'] ?? [])
        )));
        $semanticText = trim($normalized . ' ' . implode(' ', $semanticHints));
        $intentCandidates = $this->intentCandidates(
            $semanticText,
            $normalized,
            $signals,
            $trainingSignals,
            $base['date_scope'] ?? null,
            $base['comparison_scope'] ?? null
        );

        return new QueryInterpretation(
            prompt: $prompt,
            normalized: $normalized,
            languageStyle: (string) ($base['language_style'] ?? 'english'),
            semanticText: $semanticText,
            semanticHints: $semanticHints,
            dateScope: $base['date_scope'] ?? null,
            comparisonScope: $base['comparison_scope'] ?? null,
            prefersSimple: (bool) ($base['prefers_simple'] ?? false),
            domainSignals: [
                ...$signals,
                'schema_training' => [
                    'resource_count' => (int) ($trainedProfile['resource_count'] ?? 0),
                    'forecastable_resources' => (array) ($trainedProfile['forecastable_resources'] ?? []),
                ],
            ],
            resourceHints: (array) ($trainingSignals['resource_hints'] ?? []),
            conversationHints: (array) ($trainingSignals['conversation_hints'] ?? []),
            projectionHints: (array) ($trainingSignals['projection_hints'] ?? []),
            intentCandidates: $intentCandidates,
            confidence: $this->confidenceFromCandidates($intentCandidates, $trainingSignals),
        );
    }

    private function domainSignals(string $normalized): array
    {
        $signals = [
            'subjects' => [],
            'statuses' => [],
            'semantic_hints' => [],
        ];

        $subjectLexicon = [
            'student' => ['student', 'students', 'learner', 'learners', 'enrollee', 'enrollees', 'registrant', 'registrants'],
            'vehicle' => ['vehicle', 'vehicles', 'car', 'cars', 'sasakyan', 'motor', 'motors', 'bike', 'bikes', 'bicycle', 'bicycles', 'ebike', 'e bike', 'e-bike', 'electric bike', 'electric bicycle'],
            'sticker' => ['sticker', 'stickers', 'sticker application', 'sticker applications'],
            'report' => ['report', 'reports', 'incident', 'incidents', 'case', 'cases'],
        ];

        $statusLexicon = [
            'approved' => ['approved', 'accepted', 'cleared'],
            'enrolled' => ['enrolled', 'active', 'confirmed'],
            'pending' => ['pending', 'awaiting', 'processing'],
            'cancelled' => ['cancelled', 'canceled', 'dropped', 'withdrawn'],
        ];

        foreach ($subjectLexicon as $subject => $aliases) {
            if ($this->matchesAnyAlias($normalized, $aliases)) {
                $signals['subjects'][] = $subject;
                $signals['semantic_hints'][] = $subject;

                if ($subject === 'student') {
                    array_push($signals['semantic_hints'], 'students', 'enrollment', 'student_enrollments');
                } elseif ($subject === 'sticker') {
                    array_push($signals['semantic_hints'], 'sticker_applications', 'stickers', 'reviewed_by');
                } elseif ($subject === 'vehicle') {
                    array_push($signals['semantic_hints'], 'vehicles', 'vehicle registry', 'license_plate_number');
                }
            }
        }

        foreach ($statusLexicon as $status => $aliases) {
            if ($this->matchesAnyAlias($normalized, $aliases)) {
                $signals['statuses'][] = $status;
                $signals['semantic_hints'][] = $status;

                if ($status === 'enrolled') {
                    array_push($signals['semantic_hints'], 'enrollment_status', 'registered');
                }
            }
        }

        if (
            preg_match('/\bregistered\b/', $normalized) === 1
            && (
                in_array('student', $signals['subjects'], true)
                || preg_match('/\b(enrollment|student enrollment|enrollee|enrollees|learner|learners|registrant|registrants)\b/', $normalized) === 1
            )
        ) {
            $signals['statuses'][] = 'enrolled';
            array_push($signals['semantic_hints'], 'enrolled', 'enrollment_status', 'registered');
        }

        if (
            preg_match('/\bregistered\b/', $normalized) === 1
            && in_array('vehicle', $signals['subjects'], true)
        ) {
            array_push($signals['semantic_hints'], 'vehicle_registration', 'registered_vehicle');
        }

        $signals['subjects'] = array_values(array_unique($signals['subjects']));
        $signals['statuses'] = array_values(array_unique($signals['statuses']));
        $signals['semantic_hints'] = array_values(array_unique($signals['semantic_hints']));

        return $signals;
    }

    private function intentCandidates(
        string $semanticText,
        string $normalized,
        array $signals,
        array $trainingSignals,
        ?array $dateScope,
        ?array $comparisonScope,
    ): array {
        $scores = [
            'summary' => 0.4,
            'count' => 0.0,
            'trend' => 0.0,
            'growth' => 0.0,
            'top_categories' => 0.0,
            'schema' => 0.0,
            'preview' => 0.0,
            'list_resources' => 0.0,
            'projection' => 0.0,
            'lookup' => 0.0,
        ];

        if (preg_match('/\b(how many|count|record count|total records|ilan|gaano karami|number of|total number)\b/', $semanticText) === 1) {
            $scores['count'] += 3.4;
        }

        if (preg_match('/\b(trend|monthly|weekly|yearly|over time|trend ng)\b/', $semanticText) === 1) {
            $scores['trend'] += 3.1;
        }

        if (preg_match('/\b(growth|increase|decrease|lumago|tumaas|bumaba)\b/', $semanticText) === 1 || $comparisonScope !== null) {
            $scores['growth'] += 3.3;
        }

        if (preg_match('/\b(top|categories|breakdown|distribution|pinakamarami)\b/', $semanticText) === 1) {
            $scores['top_categories'] += 3.0;
        }

        if (preg_match('/\b(schema|columns|fields|structure)\b/', $semanticText) === 1) {
            $scores['schema'] += 3.0;
        }

        if (preg_match('/\b(preview|sample|rows|pakita|show)\b/', $semanticText) === 1) {
            $scores['preview'] += 2.5;
        }

        if (preg_match('/\b(list|ilista|lista|show me|pakita mo|all)\b/', $semanticText) === 1) {
            $scores['lookup'] += 1.8;
            $scores['preview'] += 0.7;
        }

        if (preg_match('/\b(list|available|resources|sources|databases)\b/', $semanticText) === 1) {
            $scores['list_resources'] += 2.7;
        }

        if (preg_match('/\b(projection|forecast|predict|estimate|projected|expected|inaasahan|inaasahang)\b/', $semanticText) === 1) {
            $scores['projection'] += 3.2;
        }

        if (($trainingSignals['projection_hints']['future_like'] ?? false) === true) {
            $scores['projection'] += 1.8;
            $scores['trend'] += 0.5;
        }

        if (($trainingSignals['projection_hints']['mode'] ?? null) === 'forecast') {
            $scores['projection'] += 1.6;
        }

        if (preg_match('/\b(license number|plate number|plate no|plaka)\b/', $semanticText) === 1) {
            $scores['lookup'] += 3.6;
        }

        if (($signals['statuses'] ?? []) !== []) {
            $scores['count'] += 1.5;
            $scores['top_categories'] += 0.6;
        }

        if (in_array('student', (array) ($signals['subjects'] ?? []), true) && ($signals['statuses'] ?? []) !== []) {
            $scores['count'] += 1.1;
        }

        if (in_array('sticker', (array) ($signals['subjects'] ?? []), true) && ($signals['statuses'] ?? []) !== []) {
            $scores['count'] += 1.1;
        }

        if ($dateScope !== null) {
            $scores['count'] += 0.5;
            $scores['trend'] += 0.7;
        }

        if (($trainingSignals['resource_hints'] ?? []) !== []) {
            $scores['count'] += 0.4;
            $scores['trend'] += 0.4;
            $scores['lookup'] += 0.4;
        }

        if (preg_match('/\b(summary|summarize|overview|describe|insight|insights|simple explanation|paliwanag|buod)\b/', $semanticText) === 1) {
            $scores['summary'] += 2.2;
        }

        if (preg_match('/\b(what about|how about|and then|then)\b/', $normalized) === 1) {
            $scores['count'] += 0.4;
            $scores['trend'] += 0.2;
        }

        if (($trainingSignals['conversation_hints']['follow_up_like'] ?? false) === true) {
            $scores['count'] += 0.5;
            $scores['trend'] += 0.5;
            $scores['projection'] += 0.4;
        }

        arsort($scores);
        $maxScore = max($scores) ?: 1.0;
        $candidates = [];

        foreach ($scores as $intent => $score) {
            if ($score <= 0) {
                continue;
            }

            $candidates[] = [
                'intent' => $intent,
                'score' => round($score, 3),
                'normalized_score' => round($score / $maxScore, 3),
            ];
        }

        return $candidates;
    }

    private function confidenceFromCandidates(array $intentCandidates, array $trainingSignals = []): float
    {
        $top = (float) ($intentCandidates[0]['score'] ?? 0.0);
        $second = (float) ($intentCandidates[1]['score'] ?? 0.0);

        if ($top <= 0.0) {
            return 0.0;
        }

        $resourceSignal = min(count((array) ($trainingSignals['resource_hints'] ?? [])), 3) * 0.04;
        $projectionSignal = (($trainingSignals['projection_hints']['mode'] ?? null) !== null) ? 0.04 : 0.0;
        $confidence = 0.3 + min($top, 5.0) * 0.1 + max($top - $second, 0.0) * 0.08 + $resourceSignal + $projectionSignal;

        return round(min($confidence, 0.98), 3);
    }

    private function matchesAnyAlias(string $normalized, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
