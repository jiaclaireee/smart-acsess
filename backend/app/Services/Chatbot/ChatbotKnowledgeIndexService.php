<?php

namespace App\Services\Chatbot;

use App\Models\ConnectedDatabase;
use App\Models\User;
use App\Services\Database\DatabaseConnectorManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ChatbotKnowledgeIndexService
{
    public function __construct(
        protected AccessibleDatabaseResolver $databaseResolver,
        protected DatabaseConnectorManager $manager,
        protected ChatbotContextBuilder $contextBuilder,
    ) {
    }

    public function loadKnowledgeForUser(User $user, bool $refreshIfNeeded = true, ?array $databaseIds = null): array
    {
        $databases = $this->filterDatabases($this->databaseResolver->forUser($user), $databaseIds);
        $snapshots = [];
        $statuses = [];

        foreach ($databases as $database) {
            $snapshot = $this->loadSnapshot($database);

            if ($refreshIfNeeded && !$this->isReadySnapshot($database, $snapshot)) {
                $snapshot = $this->syncDatabase($database, true);
            }

            $statuses[] = $this->statusRow($database, $snapshot);

            if (($snapshot['status'] ?? null) === 'ready') {
                $snapshots[] = $snapshot;
            }
        }

        return [
            'databases' => $databases->map(fn(ConnectedDatabase $database) => $database->publicMetadata())->values()->all(),
            'snapshots' => $snapshots,
            'statuses' => $statuses,
        ];
    }

    public function statusForUser(User $user): array
    {
        $payload = $this->loadKnowledgeForUser($user, false);
        $statuses = $payload['statuses'];

        return [
            'databases' => $statuses,
            'summary' => [
                'accessible_database_count' => count($statuses),
                'ready_database_count' => count(array_filter($statuses, fn(array $row) => ($row['status'] ?? null) === 'ready')),
                'stale_database_count' => count(array_filter($statuses, fn(array $row) => in_array($row['status'] ?? null, ['missing', 'stale'], true))),
                'error_database_count' => count(array_filter($statuses, fn(array $row) => ($row['status'] ?? null) === 'error')),
                'known_record_total' => array_sum(array_map(fn(array $row) => (int) ($row['overview']['known_record_total'] ?? 0), $statuses)),
            ],
        ];
    }

    public function syncForUser(User $user, ?array $databaseIds = null, bool $force = true): array
    {
        $databases = $this->filterDatabases($this->databaseResolver->forUser($user), $databaseIds);
        $results = [];

        foreach ($databases as $database) {
            $results[] = $this->statusRow($database, $this->syncDatabase($database, $force));
        }

        return [
            'message' => 'Chatbot knowledge sync completed.',
            'databases' => $results,
            'summary' => [
                'requested_database_count' => $databases->count(),
                'ready_database_count' => count(array_filter($results, fn(array $row) => ($row['status'] ?? null) === 'ready')),
                'error_database_count' => count(array_filter($results, fn(array $row) => ($row['status'] ?? null) === 'error')),
            ],
        ];
    }

    public function invalidateDatabase(int $databaseId): void
    {
        Storage::disk($this->disk())->delete($this->pathFor($databaseId));
    }

    public function syncDatabase(ConnectedDatabase $database, bool $force = true): array
    {
        $existing = $this->loadSnapshot($database);

        if (!$force && $this->isReadySnapshot($database, $existing)) {
            return $existing;
        }

        try {
            $connector = $this->manager->for($database);
            $context = $this->contextBuilder->build($database, $connector, null);
            $snapshot = [
                'version' => $this->version(),
                'status' => 'ready',
                'synced_at' => now()->toIso8601String(),
                'database_updated_at' => $database->updated_at?->toIso8601String(),
                'database' => $database->publicMetadata(),
                'resource_type' => $context['resource_type'] ?? $database->resourceLabel(),
                'summary' => $context['summary'] ?? null,
                'semantic_summary' => $context['semantic_summary'] ?? ($context['summary'] ?? null),
                'available_resources' => $context['available_resources'] ?? [],
                'resource_profiles' => $context['resource_profiles'] ?? [],
                'overview' => $context['overview'] ?? [],
                'suggested_prompts' => $context['suggested_prompts'] ?? [],
                'insufficiencies' => $context['insufficiencies'] ?? [],
            ];

            $this->persistSnapshot($database->id, $snapshot);

            return $snapshot;
        } catch (Throwable $exception) {
            Log::warning('Chatbot knowledge sync failed.', [
                'database_id' => $database->id,
                'database_name' => $database->name,
                'error' => $exception->getMessage(),
            ]);

            $snapshot = [
                'version' => $this->version(),
                'status' => 'error',
                'synced_at' => now()->toIso8601String(),
                'database_updated_at' => $database->updated_at?->toIso8601String(),
                'database' => $database->publicMetadata(),
                'resource_type' => $database->resourceLabel(),
                'summary' => 'Knowledge sync failed for this database.',
                'semantic_summary' => 'Knowledge sync failed for this database.',
                'available_resources' => [],
                'resource_profiles' => [],
                'overview' => [
                    'resource_count' => 0,
                    'profiled_resource_count' => 0,
                    'date_ready_resources' => 0,
                    'known_record_total' => 0,
                ],
                'suggested_prompts' => [],
                'insufficiencies' => [$exception->getMessage()],
                'error' => $exception->getMessage(),
            ];

            $this->persistSnapshot($database->id, $snapshot);

            return $snapshot;
        }
    }

    private function filterDatabases(Collection $databases, ?array $databaseIds): Collection
    {
        if ($databaseIds === null || $databaseIds === []) {
            return $databases;
        }

        $allowed = array_map('intval', $databaseIds);

        return $databases
            ->filter(fn(ConnectedDatabase $database) => in_array($database->id, $allowed, true))
            ->values();
    }

    private function statusRow(ConnectedDatabase $database, ?array $snapshot): array
    {
        $status = 'missing';

        if (is_array($snapshot)) {
            $status = $this->isReadySnapshot($database, $snapshot)
                ? 'ready'
                : (($snapshot['status'] ?? null) === 'error' ? 'error' : 'stale');
        }

        return [
            'database' => $database->publicMetadata(),
            'status' => $status,
            'synced_at' => $snapshot['synced_at'] ?? null,
            'summary' => $snapshot['summary'] ?? null,
            'overview' => $snapshot['overview'] ?? [
                'resource_count' => 0,
                'profiled_resource_count' => 0,
                'date_ready_resources' => 0,
                'known_record_total' => 0,
            ],
            'insufficiencies' => $snapshot['insufficiencies'] ?? [],
        ];
    }

    private function isReadySnapshot(ConnectedDatabase $database, ?array $snapshot): bool
    {
        if (!is_array($snapshot) || ($snapshot['status'] ?? null) !== 'ready') {
            return false;
        }

        if ((int) ($snapshot['version'] ?? 0) !== $this->version()) {
            return false;
        }

        return ($snapshot['database_updated_at'] ?? null) === $database->updated_at?->toIso8601String();
    }

    private function loadSnapshot(ConnectedDatabase $database): ?array
    {
        $disk = Storage::disk($this->disk());
        $path = $this->pathFor($database->id);

        if (!$disk->exists($path)) {
            return null;
        }

        $payload = json_decode((string) $disk->get($path), true);

        return is_array($payload) ? $payload : null;
    }

    private function persistSnapshot(int $databaseId, array $payload): void
    {
        Storage::disk($this->disk())->put(
            $this->pathFor($databaseId),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function disk(): string
    {
        return (string) config('chatbot.knowledge.disk', 'local');
    }

    private function pathFor(int $databaseId): string
    {
        $base = trim((string) config('chatbot.knowledge.path', 'private/chatbot-knowledge'), '/');

        return $base . '/database-' . $databaseId . '.json';
    }

    private function version(): int
    {
        return (int) config('chatbot.knowledge.version', 1);
    }
}
