<?php

namespace App\Services\Chatbot;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function getHistory(
        User $user,
        string|int $scopeKey = 'global',
        ?string $resource = null,
        string|int|null $conversationId = null,
    ): array
    {
        if (is_int($scopeKey)) {
            $scopeKey = $this->legacyScopeKey($scopeKey, $resource);
        }

        $conversation = $conversationId !== null
            ? $this->conversationModel($user, $conversationId)
            : $this->latestConversationForScope($user, $scopeKey);

        if (!$conversation instanceof ChatConversation) {
            return [];
        }

        return $this->formatMessages(
            $conversation->messages()->orderBy('id')->get()
        );
    }

    public function getConversation(User $user, string|int $conversationId): ?array
    {
        $conversation = $this->conversationModel($user, $conversationId);

        if (!$conversation instanceof ChatConversation) {
            return null;
        }

        return [
            'conversation' => $this->conversationSummary($conversation),
            'messages' => $this->formatMessages(
                $conversation->messages()->orderBy('id')->get()
            ),
        ];
    }

    public function listConversations(User $user, ?string $search = null, ?string $scopeKey = null, int $limit = 40): array
    {
        $query = ChatConversation::query()
            ->where('user_id', $user->id)
            ->when($scopeKey !== null && $scopeKey !== '', fn($builder) => $builder->where('scope_key', $scopeKey))
            ->select('chat_conversations.*')
            ->selectSub(
                ChatMessage::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('conversation_id', 'chat_conversations.id'),
                'message_count'
            )
            ->selectSub(
                ChatMessage::query()
                    ->select('content')
                    ->whereColumn('conversation_id', 'chat_conversations.id')
                    ->latest('id')
                    ->limit(1),
                'last_message_preview'
            )
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit(max($limit, 1));

        $term = trim(Str::lower((string) $search));
        if ($term !== '') {
            $like = '%' . $term . '%';

            $query->where(function ($builder) use ($like) {
                $builder
                    ->whereRaw('LOWER(COALESCE(title, \'\')) LIKE ?', [$like])
                    ->orWhereExists(function ($messageQuery) use ($like) {
                        $messageQuery
                            ->selectRaw('1')
                            ->from('chat_messages')
                            ->whereColumn('chat_messages.conversation_id', 'chat_conversations.id')
                            ->whereRaw('LOWER(chat_messages.content) LIKE ?', [$like]);
                    });
            });
        }

        return $query
            ->get()
            ->map(fn(ChatConversation $conversation) => $this->conversationSummary($conversation))
            ->values()
            ->all();
    }

    public function resolveConversation(
        User $user,
        string|int $scopeKey = 'global',
        ?string $resource = null,
        string|int|null $conversationId = null,
        bool $startNewConversation = false,
        array $meta = [],
    ): ChatConversation {
        if (is_int($scopeKey)) {
            $scopeKey = $this->legacyScopeKey($scopeKey, $resource);
        }

        if ($conversationId !== null) {
            $existing = $this->conversationModel($user, $conversationId);

            if ($existing instanceof ChatConversation) {
                return $existing;
            }
        }

        if (!$startNewConversation) {
            $latest = $this->latestConversationForScope($user, $scopeKey);

            if ($latest instanceof ChatConversation) {
                return $latest;
            }
        }

        return ChatConversation::create([
            'user_id' => $user->id,
            'database_id' => $meta['database_id'] ?? null,
            'scope_key' => $scopeKey,
            'resource' => $resource,
            'last_message_at' => null,
        ]);
    }

    public function appendTurn(
        User $user,
        string|int $scopeKey,
        array|string|null $userMessage,
        array $assistantMessage = [],
        ?array $legacyAssistantMessage = null,
        string|int|null $conversationId = null,
        bool $startNewConversation = false,
        array $meta = [],
    ): array {
        if (is_int($scopeKey)) {
            $scopeKey = $this->legacyScopeKey($scopeKey, is_string($userMessage) ? $userMessage : null);
            $userMessage = $assistantMessage;
            $assistantMessage = $legacyAssistantMessage ?? [];
        }

        $conversation = $this->resolveConversation(
            $user,
            $scopeKey,
            $meta['resource'] ?? null,
            $conversationId,
            $startNewConversation,
            $meta,
        );

        DB::transaction(function () use ($conversation, $userMessage, $assistantMessage) {
            foreach (array_filter([
                is_array($userMessage) ? $userMessage : null,
                $assistantMessage,
            ]) as $message) {
                $conversation->messages()->create([
                    'role' => (string) ($message['role'] ?? 'assistant'),
                    'content' => (string) ($message['content'] ?? ''),
                    'payload' => $this->messagePayload($message),
                    'created_at' => $message['created_at'] ?? now(),
                    'updated_at' => $message['created_at'] ?? now(),
                ]);
            }

            if (!$conversation->title && is_array($userMessage)) {
                $conversation->title = $this->conversationTitle((string) ($userMessage['content'] ?? ''));
            }

            $conversation->last_message_at = now();
            $conversation->save();
        });

        return [
            'conversation' => $this->conversationSummary($conversation->fresh()),
            'history' => $this->getHistory($user, $scopeKey, null, $conversation->id),
        ];
    }

    public function reset(
        User $user,
        string|int $scopeKey = 'global',
        ?string $resource = null,
        string|int|null $conversationId = null,
    ): ?array
    {
        if (is_int($scopeKey)) {
            $scopeKey = $this->legacyScopeKey($scopeKey, $resource);
        }

        $conversation = $conversationId !== null
            ? $this->conversationModel($user, $conversationId)
            : $this->latestConversationForScope($user, $scopeKey);

        if (!$conversation instanceof ChatConversation) {
            return null;
        }

        $conversation->messages()->delete();
        $conversation->forceFill([
            'title' => null,
            'last_message_at' => null,
        ])->save();

        return $this->conversationSummary($conversation->fresh());
    }

    private function contextKey(string $contextId): string
    {
        return 'chatbot:context:' . $contextId;
    }

    private function legacyScopeKey(int $databaseId, ?string $resource): string
    {
        return 'database:' . $databaseId . ':resource:' . ($resource ?: 'all');
    }

    private function conversationModel(User $user, string|int $conversationId): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('user_id', $user->id)
            ->find($conversationId);
    }

    private function latestConversationForScope(User $user, string $scopeKey): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('user_id', $user->id)
            ->where('scope_key', $scopeKey)
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function formatMessages(iterable $messages): array
    {
        $rows = [];

        foreach ($messages as $message) {
            $payload = is_array($message->payload) ? $message->payload : [];
            $rows[] = [
                'id' => (string) $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => optional($message->created_at)?->toIso8601String(),
                ...$payload,
            ];
        }

        return $rows;
    }

    private function messagePayload(array $message): array
    {
        $payload = $message;

        unset(
            $payload['id'],
            $payload['role'],
            $payload['content'],
            $payload['created_at']
        );

        return $payload;
    }

    private function conversationSummary(ChatConversation $conversation): array
    {
        $messageCount = (int) ($conversation->message_count ?? $conversation->messages()->count());
        $preview = $conversation->last_message_preview
            ?? $conversation->messages()->latest('id')->value('content')
            ?? '';

        return [
            'id' => (string) $conversation->id,
            'title' => $conversation->title ?: 'New conversation',
            'scope_key' => $conversation->scope_key,
            'resource' => $conversation->resource,
            'database_id' => $conversation->database_id,
            'message_count' => $messageCount,
            'preview' => Str::limit(trim((string) $preview), 120),
            'last_message_at' => optional($conversation->last_message_at)?->toIso8601String(),
            'updated_at' => optional($conversation->updated_at)?->toIso8601String(),
        ];
    }

    private function conversationTitle(string $content): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $content) ?? '');
        $title = $title !== '' ? $title : 'New conversation';

        return Str::limit($title, 80, '...');
    }
}
