<?php

namespace App\Services\Chatbot;

readonly class QueryInterpretation
{
    public function __construct(
        public string $prompt,
        public string $normalized,
        public string $languageStyle,
        public string $semanticText,
        public array $semanticHints,
        public ?array $dateScope,
        public ?array $comparisonScope,
        public bool $prefersSimple,
        public array $domainSignals,
        public array $resourceHints,
        public array $conversationHints,
        public array $projectionHints,
        public array $intentCandidates,
        public float $confidence,
    ) {
    }

    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'normalized' => $this->normalized,
            'language_style' => $this->languageStyle,
            'semantic_text' => $this->semanticText,
            'semantic_hints' => $this->semanticHints,
            'date_scope' => $this->dateScope,
            'comparison_scope' => $this->comparisonScope,
            'prefers_simple' => $this->prefersSimple,
            'domain_signals' => $this->domainSignals,
            'resource_hints' => $this->resourceHints,
            'conversation_hints' => $this->conversationHints,
            'projection_hints' => $this->projectionHints,
            'intent_candidates' => $this->intentCandidates,
            'interpretation_confidence' => $this->confidence,
        ];
    }
}
