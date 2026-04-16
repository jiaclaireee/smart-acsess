<?php

namespace Tests\Feature;

use App\Models\ConnectedDatabase;
use App\Models\User;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DatabaseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_connection_metadata_without_exposing_password(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $response = $this->postJson('/api/databases', [
            'name' => 'MySQL Main',
            'type' => ConnectedDatabase::TYPE_MYSQL,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database_name' => 'smart_acsess',
            'username' => 'root',
            'password' => 'Secret!123',
            'extra_config' => ['charset' => 'utf8mb4'],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('type', ConnectedDatabase::TYPE_MYSQL)
            ->assertJsonPath('password_configured', true)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('password_encrypted');

        $database = ConnectedDatabase::firstOrFail();

        $this->assertSame('Secret!123', $database->password);
        $this->assertSame(['charset' => 'utf8mb4'], $database->extra_config);
    }

    public function test_admin_can_list_registered_and_custom_database_type_options(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/databases/options')
            ->assertOk()
            ->assertJsonFragment(['key' => ConnectedDatabase::TYPE_POSTGRESQL, 'label' => 'PostgreSQL'])
            ->assertJsonFragment(['key' => ConnectedDatabase::TYPE_MARIADB, 'label' => 'MariaDB'])
            ->assertJsonFragment(['key' => '__custom__', 'label' => 'Other / Custom']);
    }

    public function test_admin_can_store_custom_connection_type_metadata(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $response = $this->postJson('/api/databases', [
            'name' => 'Oracle Warehouse',
            'type' => 'oracle',
            'host' => '10.0.0.50',
            'port' => 1521,
            'database_name' => 'xe',
            'username' => 'system',
            'password' => 'Secret!123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('type', 'oracle')
            ->assertJsonPath('type_label', 'Oracle')
            ->assertJsonPath('connector_registered', false)
            ->assertJsonPath('password_configured', true);
    }

    public function test_store_normalizes_username_and_port_when_pasted_into_host_field(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $response = $this->postJson('/api/databases', [
            'name' => 'Vehicle Registration Database (simulation)',
            'type' => ConnectedDatabase::TYPE_POSTGRESQL,
            'host' => 'npg_a2IjA4POuTeg@ep-proud-hat-a4a8c79v-pooler.us-east-1.aws.neon.tech:5431',
            'database_name' => 'neondb_simulation',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('host', 'ep-proud-hat-a4a8c79v-pooler.us-east-1.aws.neon.tech')
            ->assertJsonPath('port', 5431)
            ->assertJsonPath('username', 'npg_a2IjA4POuTeg');

        $database = ConnectedDatabase::firstOrFail();

        $this->assertSame('ep-proud-hat-a4a8c79v-pooler.us-east-1.aws.neon.tech', $database->host);
        $this->assertSame(5431, $database->port);
        $this->assertSame('npg_a2IjA4POuTeg', $database->username);
    }

    public function test_index_returns_normalized_metadata_for_legacy_connections(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $database = new ConnectedDatabase();
        $database->name = 'Legacy Neon';
        $database->type = ConnectedDatabase::TYPE_POSTGRESQL;
        $database->host = 'legacy_user@legacy-host.neon.tech:5433';
        $database->port = null;
        $database->database_name = 'legacy_db';
        $database->username = null;
        $database->connection_string = 'postgresql://legacy_user@legacy-host.neon.tech:5433/legacy_db';
        $database->save();

        $this->getJson('/api/databases')
            ->assertOk()
            ->assertJsonPath('0.host', 'legacy-host.neon.tech')
            ->assertJsonPath('0.port', 5433)
            ->assertJsonPath('0.username', 'legacy_user');
    }

    public function test_test_connection_returns_clear_message_for_neon_connection_missing_password(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $database = ConnectedDatabase::create([
            'name' => 'Neon Simulation',
            'type' => ConnectedDatabase::TYPE_POSTGRESQL,
            'host' => 'ep-proud-hat-a4a8c79v-pooler.us-east-1.aws.neon.tech',
            'port' => 5431,
            'database_name' => 'neondb_simulation',
            'username' => 'neondb_owner',
            'connection_string_encrypted' => Crypt::encryptString('postgresql://neondb_owner@ep-proud-hat-a4a8c79v-pooler.us-east-1.aws.neon.tech:5431/neondb_simulation'),
        ]);

        $this->postJson("/api/databases/{$database->id}/test")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This Neon PostgreSQL connection is missing the password. Edit the connection and enter the full Neon credentials. The username is the database role, and the password is the `npg_...` secret.'
            );
    }

    public function test_non_admin_users_cannot_manage_connections(): void
    {
        $user = User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_END_USER,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/databases', [
            'name' => 'Blocked',
            'type' => ConnectedDatabase::TYPE_POSTGRESQL,
            'host' => '127.0.0.1',
            'database_name' => 'smart_acsess',
        ])->assertForbidden();
    }

    public function test_admin_can_test_and_inspect_connections_through_connector_manager(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $database = $this->makeDatabaseConnection();
        $connector = new class implements DatabaseConnector {
            public function resourceType(): string
            {
                return 'table';
            }

            public function testConnection(): array
            {
                return ['message' => 'Connection successful.', 'resource_type' => 'table'];
            }

            public function listResources(): array
            {
                return ['incidents', 'users'];
            }

            public function getSchema(?string $resource = null): array
            {
                return [[
                    'table' => $resource ?? 'incidents',
                    'resource' => $resource ?? 'incidents',
                    'columns' => [
                        ['name' => 'id', 'type' => 'integer'],
                    ],
                ]];
            }

            public function previewRows(string $resource, array $filters = [], int $limit = 50): array
            {
                return [];
            }

            public function paginateRows(string $resource, array $filters = [], int $page = 1, int $perPage = 25): array
            {
                return [
                    'rows' => [],
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => 0,
                        'last_page' => 1,
                        'from' => null,
                        'to' => null,
                    ],
                ];
            }

            public function countRecords(string $resource, array $filters = []): int|float
            {
                return 0;
            }

            public function aggregateByGroup(
                string $resource,
                string $groupColumn,
                string $metric = 'count',
                ?string $valueColumn = null,
                array $filters = [],
                int $limit = 10,
            ): array {
                return [];
            }

            public function aggregateByDate(
                string $resource,
                string $dateColumn,
                string $metric = 'count',
                ?string $valueColumn = null,
                array $filters = [],
                string $period = 'daily',
                int $limit = 100,
            ): array {
                return [];
            }

            public function getAggregateData(
                string $resource,
                string $metric,
                ?string $valueColumn = null,
                ?string $dateColumn = null,
                array $filters = [],
                string $period = 'none',
                int $limit = 50,
            ): array {
                return ['summary' => 0, 'series' => [], 'rows' => []];
            }
        };

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->times(3)->andReturn($connector);
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $this->postJson("/api/databases/{$database->id}/test")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Connection successful.');

        $this->getJson("/api/databases/{$database->id}/tables")
            ->assertOk()
            ->assertJsonPath('resource_type', 'table')
            ->assertJsonPath('items.0', 'incidents');

        $this->getJson("/api/databases/{$database->id}/schema?resource=incidents")
            ->assertOk()
            ->assertJsonPath('schema.0.table', 'incidents')
            ->assertJsonPath('schema.0.columns.0.name', 'id');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@up.edu.ph',
        ]);
    }

    private function makeDatabaseConnection(): ConnectedDatabase
    {
        $database = new ConnectedDatabase();
        $database->name = 'Analytics PG';
        $database->type = ConnectedDatabase::TYPE_POSTGRESQL;
        $database->host = '127.0.0.1';
        $database->port = 5432;
        $database->database_name = 'smart_acsess';
        $database->username = 'postgres';
        $database->password = 'secret';
        $database->connection_string = 'postgresql://postgres:secret@127.0.0.1:5432/smart_acsess';
        $database->save();

        return $database;
    }
}
