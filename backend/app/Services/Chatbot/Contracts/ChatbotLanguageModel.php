<?php

namespace App\Services\Chatbot\Contracts;

interface ChatbotLanguageModel
{
    public function planGroundedInterpretation(
        string $prompt,
        array $context,
        array $history,
        array $trainingProfile,
        array $baselineInterpretation,
    ): ?array;

    public function formatGroundedResponse(string $prompt, array $context, array $analysis): ?string;
}
