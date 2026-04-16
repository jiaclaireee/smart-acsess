<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectedDatabase;
use App\Services\Chatbot\ChatbotKnowledgeIndexService;
use App\Services\Database\DatabaseConnectorException;
use App\Services\Database\DatabaseConnectorManager;
use App\Services\Database\DatabaseDriverRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Throwable;

class ConnectedDatabaseController extends Controller
{
    public function __construct(protected DatabaseDriverRegistry $registry)
    {
    }

    public function index()
    {
        return response()->json(
            ConnectedDatabase::orderBy('id', 'desc')
                ->get()
                ->map(fn(ConnectedDatabase $database) => $database->publicMetadata())
                ->values()
        );
    }

    public function store(Request $request, ChatbotKnowledgeIndexService $knowledgeIndex)
    {
        $data = $this->validatePayload($request);

        $database = new ConnectedDatabase();
        $this->fillDatabase($database, $data);
        $database->save();
        $knowledgeIndex->invalidateDatabase($database->id);

        return response()->json($database->publicMetadata(), 201);
    }

    public function show(ConnectedDatabase $database)
    {
        return response()->json($database->publicMetadata());
    }

    public function options()
    {
        return response()->json($this->registry->options());
    }

    public function update(Request $request, ConnectedDatabase $database, ChatbotKnowledgeIndexService $knowledgeIndex)
    {
        $data = $this->validatePayload($request, $database);

        $this->fillDatabase($database, $data);
        $database->save();
        $knowledgeIndex->invalidateDatabase($database->id);

        return response()->json($database->publicMetadata());
    }

    public function destroy(ConnectedDatabase $database, ChatbotKnowledgeIndexService $knowledgeIndex)
    {
        $knowledgeIndex->invalidateDatabase($database->id);
        $database->delete();
        return response()->json(['ok' => true]);
    }

    public function testConnection(ConnectedDatabase $database, DatabaseConnectorManager $manager)
    {
        try {
            return response()->json([
                'ok' => true,
                ...$manager->for($database)->testConnection(),
            ]);
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to connect using the saved configuration.',
            ], 422);
        }
    }

    public function tables(ConnectedDatabase $database, DatabaseConnectorManager $manager)
    {
        try {
            $items = $manager->for($database)->listResources();
            $resourceType = $database->resourceLabel();

            return response()->json([
                'resource_type' => $resourceType,
                'items' => $items,
                'tables' => $resourceType === 'table' ? $items : [],
                'collections' => $resourceType === 'collection' ? $items : [],
            ]);
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to load the saved database structure.',
            ], 422);
        }
    }

    private function validatePayload(Request $request, ?ConnectedDatabase $database = null): array
    {
        $rules = [
            'name' => [$database ? 'sometimes' : 'required', 'required', 'string', 'max:200'],
            'type' => [$database ? 'sometimes' : 'required', 'required', 'string', 'max:100'],
            'host' => [$database ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'database_name' => [$database ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'extra_config' => ['nullable', 'array'],
            'clear_password' => ['nullable', 'boolean'],
            'clear_extra_config' => ['nullable', 'boolean'],
        ];

        $data = $request->validate($rules);
        $type = array_key_exists('type', $data)
            ? $this->normalizeType($data['type'])
            : $database?->type;

        if ($type === '') {
            throw ValidationException::withMessages([
                'type' => ['Database type must contain letters or numbers.'],
            ]);
        }

        $data['type'] = $type;

        if (empty($data['port']) && !$database?->port) {
            $data['port'] = $this->registry->defaultPort($type);
        }

        return $data;
    }

    private function fillDatabase(ConnectedDatabase $database, array $data): void
    {
        foreach (['name', 'type', 'host', 'port', 'database_name', 'username'] as $field) {
            if (array_key_exists($field, $data)) {
                $database->{$field} = $data[$field];
            }
        }

        if (!empty($data['password'])) {
            $database->password = $data['password'];
        } elseif (!empty($data['clear_password'])) {
            $database->clearPassword();
        }

        if (array_key_exists('extra_config', $data)) {
            $database->extra_config = $data['extra_config'];
        } elseif (!empty($data['clear_extra_config'])) {
            $database->clearExtraConfig();
        }

        $resolved = ConnectedDatabase::normalizeConnectionAttributes([
            'host' => $database->host,
            'port' => $database->port,
            'database_name' => $database->database_name,
            'username' => $database->username,
            'password' => $database->password,
            'extra_config' => $database->extra_config,
        ]);

        $database->host = $resolved['host'];
        $database->port = $resolved['port'] ?? $database->port;
        $database->database_name = $resolved['database_name'] ?? $database->database_name;
        $database->username = $resolved['username'] ?? $database->username;

        if (!empty($resolved['password']) && empty($database->password)) {
            $database->password = $resolved['password'];
        }

        if (array_key_exists('extra_config', $resolved)) {
            $database->extra_config = $resolved['extra_config'];
        }

        $database->connection_string = $this->buildCompatibilityConnectionString($database);
    }

    private function buildCompatibilityConnectionString(ConnectedDatabase $database): string
    {
        $resolved = $database->resolvedConnectionDetails();
        $username = !empty($resolved['username']) ? rawurlencode($resolved['username']) : '';
        $password = !empty($resolved['password']) ? ':' . rawurlencode($resolved['password']) : '';
        $credentials = $username ? "{$username}{$password}@" : '';
        $port = !empty($resolved['port']) ? ':' . $resolved['port'] : '';
        $databaseName = !empty($resolved['database_name']) ? '/' . ltrim($resolved['database_name'], '/') : '';

        return match ($database->type) {
            ConnectedDatabase::TYPE_POSTGRESQL => "postgresql://{$credentials}{$resolved['host']}{$port}{$databaseName}",
            ConnectedDatabase::TYPE_MYSQL => "mysql://{$credentials}{$resolved['host']}{$port}{$databaseName}",
            ConnectedDatabase::TYPE_MARIADB => "mariadb://{$credentials}{$resolved['host']}{$port}{$databaseName}",
            ConnectedDatabase::TYPE_SQL_SERVER => "sqlsrv://{$credentials}{$resolved['host']}{$port}{$databaseName}",
            ConnectedDatabase::TYPE_MONGODB => "mongodb://{$credentials}{$resolved['host']}{$port}{$databaseName}",
            default => "{$database->type}://{$credentials}{$resolved['host']}{$port}{$databaseName}",
        };
    }

    private function normalizeType(string $type): string
    {
        return $this->registry->normalize(Str::lower($type));
    }
}
