<?php

namespace App\Services\Chatbot;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ChatbotHistoryStore
{
    public function rememberContext(User $user, string|int $scopeKey, array|string|null $context = null, array $meta = []): array
    {
        if (is_int($scopeKey)) {
            $scopeKey = $this->legacyScopeKey($scopeKey, is_string($context) ? $context : null);
            $context = $meta;
            $meta = [];
        }

        $contextId = (string) Str::uuid();
        $payload = [
            'id' => $contextId,
            'user_id' => $user->id,
            'scope_key' => $scopeKey,
            'context' => is_array($context) ? $context : [],
            'created_at' => now()->toIso8601String(),
            ...$meta,
        ];

        Cache::put(
            $this->contextKey($contextId),
            $payload,
            now()->addMinutes((int) config('chatbot.context_ttl_minutes', 30))
        );

        return $payload;
    }

    public function getContext(User $user, string $contextId): ?array
    {
        $payload = Cache::get($this->contextKey($contextId));

        if (!is_array($payload) || ($payload['user_id'] ?? null) !== $user->id) {
            return null;
        }

        return $payload;
    }

    public function getHistory(User $user, string|int $scopeKey = 'global', ?string $resource = null): array
    {
        if (is_int($scopeKey)) {
            $scopeKey = $this->legacyScopeKey($scopeKey, $resource);
        }

        $history = Cache::get($this->historyKey($user, $scopeKey), []);

        return is_array($history) ? array_values($history) : [];
    }

    public function appendTurn(
        User $user,
        string|int $scopeKey,
        array|string|null $userMessage,
        array $assistantMessage = [],
        ?array $legacyAssistantMessage = null,
    ): array {
        if (is_int($scopeKey)) {
            $scopeKey = $this->legacyScopeKey($scopeKey, is_string($userMessage) ? $userMessage : null);
            $userMessage = $assistantMessage;
            $assistantMessage = $legacyAssistantMessage ?? [];
        }

        $history = $this->getHistory($user, $scopeKey);
        $history[] = is_array($userMessage) ? $userMessage : [];
        $history[] = $assistantMessage;

        $history = array_slice($history, -1 * max((int) config('chatbot.history_limit', 20), 2));

        Cache::put(
            $this->historyKey($user, $scopeKey),
            $history,
            now()->addMinutes((int) config('chatbot.history_ttl_minutes', 240))
        );

        return $history;
    }

    public function reset(User $user, string|int $scopeKey = 'global', ?string $resource = null): void
    {
        if (is_int($scopeKey)) {
            $scopeKey = $this->legacyScopeKey($scopeKey, $resource);
        }

        Cache::forget($this->historyKey($user, $scopeKey));
    }

    private function contextKey(string $contextId): string
    {
        return 'chatbot:context:' . $contextId;
    }

    private function historyKey(User $user, string $scopeKey): string
    {
        return sprintf(
            'chatbot:history:%d:%s',
            $user->id,
            $scopeKey
        );
    }

    private function legacyScopeKey(int $databaseId, ?string $resource): string
    {
        return 'database:' . $databaseId . ':resource:' . ($resource ?: 'all');
    }
}
