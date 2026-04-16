<?php

namespace App\Services\Chatbot\LanguageModels;

use App\Services\Chatbot\Contracts\ChatbotLanguageModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiCompatibleChatbotLanguageModel implements ChatbotLanguageModel
{
    public function __construct(protected array $config)
    {
    }

    public function planGroundedInterpretation(
        string $prompt,
        array $context,
        array $history,
        array $trainingProfile,
        array $baselineInterpretation,
    ): ?array {
        if (!$this->isEnabled() || !(bool) ($this->config['planning_enabled'] ?? true)) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->baseUrl($this->config['base_url'])
                ->withToken($this->config['api_key'])
                ->timeout((int) ($this->config['timeout'] ?? 20))
                ->post('/chat/completions', [
                    'model' => $this->config['model'],
                    'temperature' => 0.0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a schema-aware grounded query planner. Use only the supplied database training profile and conversation history. Return only a compact JSON object with keys intent, semantic_hints, resource_hints, projection_hints, and conversation_hints. Do not invent resources that are not in the training profile.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'prompt' => $prompt,
                                'context' => [
                                    'resource_type' => $context['resource_type'] ?? null,
                                    'selected_resource' => $context['selected_resource'] ?? null,
                                ],
                                'history' => array_slice($history, -4),
                                'training_profile' => [
                                    'resources' => array_slice((array) ($trainingProfile['resources'] ?? []), 0, 12),
                                    'forecastable_resources' => (array) ($trainingProfile['forecastable_resources'] ?? []),
                                    'intent_examples' => array_slice((array) ($trainingProfile['intent_examples'] ?? []), 0, 16),
                                ],
                                'baseline_interpretation' => [
                                    'intent_candidates' => (array) ($baselineInterpretation['intent_candidates'] ?? []),
                                    'resource_hints' => (array) ($baselineInterpretation['resource_hints'] ?? []),
                                    'projection_hints' => (array) ($baselineInterpretation['projection_hints'] ?? []),
                                    'conversation_hints' => (array) ($baselineInterpretation['conversation_hints'] ?? []),
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Chatbot LLM planner returned a non-success response.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            if ($content === '') {
                return null;
            }

            $payload = $this->extractJsonPayload($content);

            return is_array($payload) ? $payload : null;
        } catch (Throwable $exception) {
            Log::warning('Chatbot LLM planner failed.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function formatGroundedResponse(string $prompt, array $context, array $analysis): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->baseUrl($this->config['base_url'])
                ->withToken($this->config['api_key'])
                ->timeout((int) ($this->config['timeout'] ?? 20))
                ->post('/chat/completions', [
                    'model' => $this->config['model'],
                    'temperature' => 0.1,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a database-grounded assistant. Use only the supplied JSON facts. If the facts are insufficient, say that clearly. Do not invent rows, counts, columns, or trends. Keep the answer concise and practical.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'prompt' => $prompt,
                                'context' => [
                                    'database' => $context['database'] ?? [],
                                    'selected_resource' => $context['selected_resource'] ?? null,
                                    'resource_type' => $context['resource_type'] ?? null,
                                ],
                                'analysis' => [
                                    'intent' => $analysis['intent'] ?? null,
                                    'insufficient_data' => $analysis['insufficient_data'] ?? false,
                                    'facts' => $analysis['facts'] ?? [],
                                    'warnings' => $analysis['warnings'] ?? [],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Chatbot LLM formatter returned a non-success response.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

            return $content !== '' ? $content : null;
        } catch (Throwable $exception) {
            Log::warning('Chatbot LLM formatter failed.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && !empty($this->config['api_key'])
            && !empty($this->config['model'])
            && !empty($this->config['base_url']);
    }

    private function extractJsonPayload(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = substr($content, $start, ($end - $start) + 1);
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }
}
