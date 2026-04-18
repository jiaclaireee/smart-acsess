<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\ConnectedDatabase;
use App\Models\User;
use App\Services\Chatbot\UnifiedChatbotService;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorManager;
use App\Services\DatabaseReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AuditTrailFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_audit_trails(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        AuditTrail::create([
            'user_id' => $admin->id,
            'user_name' => 'Admin User',
            'user_email' => $admin->email,
            'module' => 'Dashboard',
            'action' => 'Set Dashboard Filter',
            'description' => 'Generated dashboard results using the selected filters.',
            'metadata' => ['graph_type' => 'bar'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->getJson('/api/audit-trails')
            ->assertOk()
            ->assertJsonPath('data.0.module', 'Dashboard')
            ->assertJsonPath('data.0.action', 'Set Dashboard Filter');
    }

    public function test_non_admin_cannot_list_audit_trails(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());

        $this->getJson('/api/audit-trails')
            ->assertForbidden();
    }

    public function test_creating_a_user_and_database_creates_audit_trails(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/users', [
            'first_name' => 'Sample',
            'last_name' => 'User',
            'email' => 'sample@up.edu.ph',
            'password' => 'StrongPass!123',
            'role' => User::ROLE_END_USER,
            'approval_status' => User::APPROVAL_APPROVED,
        ])->assertCreated();

        $this->postJson('/api/databases', [
            'name' => 'Audit DB',
            'type' => ConnectedDatabase::TYPE_POSTGRESQL,
            'host' => '127.0.0.1',
            'port' => 5432,
            'database_name' => 'smart_acsess',
            'username' => 'postgres',
            'password' => 'Secret!123',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_trails', [
            'module' => 'User Management',
            'action' => 'Add User',
        ]);

        $this->assertDatabaseHas('audit_trails', [
            'module' => 'Database Connections',
            'action' => 'Add Database',
        ]);
    }

    public function test_dashboard_report_and_export_create_audit_trails(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $reporting = Mockery::mock(DatabaseReportingService::class);
        $reporting->shouldReceive('buildReport')->twice()->andReturn($this->fakeDashboardReport($database));
        $this->app->instance(DatabaseReportingService::class, $reporting);

        $connector = new class implements DatabaseConnector {
            public function resourceType(): string
            {
                return 'table';
            }

            public function testConnection(): array
            {
                return ['message' => 'ok'];
            }

            public function listResources(): array
            {
                return [];
            }

            public function getSchema(?string $resource = null): array
            {
                return [];
            }

            public function previewRows(string $resource, array $filters = [], int $limit = 50): array
            {
                return [];
            }

            public function paginateRows(string $resource, array $filters = [], int $page = 1, int $perPage = 25): array
            {
                return ['rows' => [], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null]];
            }

            public function countRecords(string $resource, array $filters = []): int|float
            {
                return 0;
            }

            public function aggregateByGroup(string $resource, string $groupColumn, string $metric = 'count', ?string $valueColumn = null, array $filters = [], int $limit = 10): array
            {
                return [];
            }

            public function aggregateByDate(string $resource, string $dateColumn, string $metric = 'count', ?string $valueColumn = null, array $filters = [], string $period = 'daily', int $limit = 100): array
            {
                return [];
            }

            public function getAggregateData(string $resource, string $metric, ?string $valueColumn = null, ?string $dateColumn = null, array $filters = [], string $period = 'none', int $limit = 50): array
            {
                return ['summary' => 0, 'series' => [], 'rows' => []];
            }
        };

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->twice()->andReturn($connector);
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $payload = [
            'db_id' => $database->id,
            'resource' => 'incidents',
            'table' => 'incidents',
            'graph_type' => 'bar',
            'period' => 'monthly',
            'date_column' => 'reported_at',
            'group_column' => 'category',
            'sort_by' => 'reported_at',
            'sort_direction' => 'desc',
        ];

        $this->postJson('/api/analytics/report', $payload)->assertOk();
        $this->postJson('/api/reports/dashboard-pdf', $payload)->assertOk();

        $this->assertDatabaseHas('audit_trails', [
            'module' => 'Dashboard',
            'action' => 'Set Dashboard Filter',
        ]);

        $this->assertDatabaseHas('audit_trails', [
            'module' => 'Dashboard',
            'action' => 'Export Dashboard Report',
        ]);

        $filterTrail = AuditTrail::query()->where('action', 'Set Dashboard Filter')->latest()->first();
        $exportTrail = AuditTrail::query()->where('action', 'Export Dashboard Report')->latest()->first();

        $this->assertNotNull($filterTrail);
        $this->assertNotNull($exportTrail);
        $this->assertSame('Analytics PG', $filterTrail->metadata['database_name'] ?? null);
        $this->assertSame('incidents', $filterTrail->metadata['selected_table'] ?? null);
        $this->assertSame('reported_at', $filterTrail->metadata['date_column'] ?? null);
        $this->assertSame('category', $filterTrail->metadata['group_column'] ?? null);
        $this->assertSame('reported_at', $exportTrail->metadata['date_column'] ?? null);
        $this->assertSame('category', $exportTrail->metadata['group_column'] ?? null);
        $this->assertSame('desc', $exportTrail->metadata['sort_direction'] ?? null);
    }

    public function test_chatbot_actions_create_audit_trails(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());

        $chatbot = Mockery::mock(UnifiedChatbotService::class);
        $chatbot->shouldReceive('ask')->once()->andReturn([
            'conversation' => ['id' => '42'],
            'intent' => 'trend',
            'language_style' => 'english',
            'table' => ['rows' => [['label' => 'Open', 'value' => 20]]],
            'chart' => ['labels' => ['Open'], 'series' => [20]],
        ]);
        $this->app->instance(UnifiedChatbotService::class, $chatbot);

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'Show me the monthly trend.',
        ])->assertOk();

        $this->postJson('/api/chatbot/export/table-pdf', [
            'title' => 'Chatbot Export',
            'table' => [
                'columns' => ['label', 'value'],
                'rows' => [['label' => 'Open', 'value' => 20]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('audit_trails', [
            'module' => 'Chatbot',
            'action' => 'Chatbot Conversation',
        ]);

        $this->assertDatabaseHas('audit_trails', [
            'module' => 'Chatbot',
            'action' => 'Export Chatbot Report',
        ]);
    }

    public function test_exporting_developer_documentation_creates_audit_trail(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->get('/api/developers/documentation/pdf')->assertOk();

        $this->assertDatabaseHas('audit_trails', [
            'module' => 'SMART-ACSESS for Developers',
            'action' => 'Export Developer Documentation',
        ]);
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

    private function makeApprovedEndUser(): User
    {
        return User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_END_USER,
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

    private function fakeDashboardReport(ConnectedDatabase $database): array
    {
        return [
            'database' => $database->publicMetadata(),
            'resource_type' => 'table',
            'selected_resource' => 'incidents',
            'period' => 'monthly',
            'warnings' => [],
            'kpis' => [
                ['label' => 'Total Records', 'value' => 10, 'hint' => 'Sample KPI'],
            ],
            'chart' => [
                'type' => 'bar',
                'title' => 'Incidents by month',
                'labels' => ['2026-04-01'],
                'series' => [10],
                'meta' => ['mode' => 'date', 'group_by' => 'created_at'],
            ],
            'table' => [
                'columns' => ['id', 'status'],
                'rows' => [
                    ['id' => 1, 'status' => 'Open'],
                ],
                'pagination' => [
                    'page' => 1,
                    'per_page' => 25,
                    'total' => 1,
                    'last_page' => 1,
                    'from' => 1,
                    'to' => 1,
                ],
            ],
        ];
    }
}
