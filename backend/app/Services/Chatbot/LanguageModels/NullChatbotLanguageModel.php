<?php

namespace App\Services\Chatbot\LanguageModels;

use App\Services\Chatbot\Contracts\ChatbotLanguageModel;

class NullChatbotLanguageModel implements ChatbotLanguageModel
{
    public function planGroundedInterpretation(
        string $prompt,
        array $context,
        array $history,
        array $trainingProfile,
        array $baselineInterpretation,
    ): ?array {
        return null;
    }

    public function formatGroundedResponse(string $prompt, array $context, array $analysis): ?string
    {
        return null;
    }
}
