<?php

namespace Tests\Feature;

use App\Models\ConnectedDatabase;
use App\Models\User;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_end_user_can_generate_a_resource_level_dashboard_report(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->once()->andReturn($this->makeConnector());
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $this->postJson('/api/analytics/report', [
            'db_id' => $database->id,
            'resource' => 'incidents',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'period' => 'monthly',
            'graph_type' => 'line',
            'page' => 1,
            'per_page' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('selected_resource', 'incidents')
            ->assertJsonPath('period', 'monthly')
            ->assertJsonPath('schema.detected.date_column', 'created_at')
            ->assertJsonPath('chart.meta.mode', 'date')
            ->assertJsonPath('table.pagination.total', 5)
            ->assertJsonPath('capabilities.supports_date_filtering', true);
    }

    public function test_dashboard_report_can_return_database_overview_when_no_resource_is_selected(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->once()->andReturn($this->makeConnector());
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $this->postJson('/api/analytics/report', [
            'db_id' => $database->id,
            'graph_type' => 'bar',
        ])
            ->assertOk()
            ->assertJsonPath('selected_resource', null)
            ->assertJsonPath('chart.meta.mode', 'resource_overview')
            ->assertJsonPath('table.columns.0', 'resource')
            ->assertJsonPath('table.rows.0.resource', 'incidents');
    }

    public function test_selected_visualization_column_is_used_even_if_it_is_not_auto_detected_as_groupable(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->once()->andReturn($this->makeConnector());
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $this->postJson('/api/analytics/report', [
            'db_id' => $database->id,
            'resource' => 'incidents',
            'graph_type' => 'pie',
            'group_column' => 'classification',
        ])
            ->assertOk()
            ->assertJsonPath('chart.meta.mode', 'group')
            ->assertJsonPath('chart.meta.group_by', 'classification');
    }

    public function test_chart_group_drilldown_returns_only_matching_rows(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->once()->andReturn($this->makeConnector());
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $this->postJson('/api/analytics/drilldown', [
            'db_id' => $database->id,
            'resource' => 'incidents',
            'chart_mode' => 'group',
            'chart_group_by' => 'status',
            'bucket_label' => 'Open',
            'page' => 1,
            'per_page' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('selection.mode', 'group')
            ->assertJsonPath('selection.group_by', 'status')
            ->assertJsonPath('table.pagination.total', 2)
            ->assertJsonCount(2, 'table.rows')
            ->assertJsonPath('table.rows.0.status', 'Open')
            ->assertJsonPath('table.rows.1.status', 'Open');
    }

    public function test_chart_date_drilldown_respects_global_filters_and_selected_bucket(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->once()->andReturn($this->makeConnector());
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $this->postJson('/api/analytics/drilldown', [
            'db_id' => $database->id,
            'resource' => 'incidents',
            'from' => '2026-01-15',
            'to' => '2026-01-31',
            'period' => 'monthly',
            'date_column' => 'created_at',
            'chart_mode' => 'date',
            'chart_group_by' => 'created_at',
            'bucket_label' => '2026-01-01',
            'page' => 1,
            'per_page' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('selection.mode', 'date')
            ->assertJsonPath('selection.period', 'monthly')
            ->assertJsonPath('table.pagination.total', 3)
            ->assertJsonCount(3, 'table.rows')
            ->assertJsonPath('table.rows.0.id', 3)
            ->assertJsonPath('table.rows.2.id', 5);
    }

    public function test_dashboard_report_rejects_an_invalid_date_range(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $this->postJson('/api/analytics/report', [
            'db_id' => $database->id,
            'resource' => 'incidents',
            'from' => '2026-02-01',
            'to' => '2026-01-01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_dashboard_whole_database_overview_skips_problem_resources(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->once()->andReturn(new class implements DatabaseConnector {
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
                return ['broken_table', 'incidents'];
            }

            public function getSchema(?string $resource = null): array
            {
                if ($resource === 'broken_table') {
                    throw new \RuntimeException('Broken schema');
                }

                return [[
                    'table' => 'incidents',
                    'resource' => 'incidents',
                    'columns' => [
                        ['name' => 'created_at', 'type' => 'timestamp'],
                    ],
                ]];
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
                if ($resource === 'broken_table') {
                    throw new \RuntimeException('Broken count');
                }

                return 7;
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
        });
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $this->postJson('/api/analytics/report', [
            'db_id' => $database->id,
            'graph_type' => 'bar',
        ])
            ->assertOk()
            ->assertJsonPath('table.rows.0.resource', 'incidents')
            ->assertJsonFragment(['record_count' => 7]);
    }

    public function test_approved_user_can_export_current_dashboard_report_as_pdf(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection();

        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->once()->andReturn($this->makeConnector());
        $this->app->instance(DatabaseConnectorManager::class, $manager);

        $response = $this->postJson('/api/reports/dashboard-pdf', [
            'db_id' => $database->id,
            'resource' => 'incidents',
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'period' => 'monthly',
            'graph_type' => 'bar',
            'page' => 1,
            'per_page' => 10,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'report-analytics-pg-',
            (string) $response->headers->get('content-disposition')
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
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

    private function makeConnector(): DatabaseConnector
    {
        return new class implements DatabaseConnector {
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
                return ['incidents', 'responders'];
            }

            public function getSchema(?string $resource = null): array
            {
                $schemas = [
                    'incidents' => [[
                        'table' => 'incidents',
                        'resource' => 'incidents',
                        'columns' => [
                            ['name' => 'id', 'type' => 'integer'],
                            ['name' => 'classification', 'type' => 'user-defined'],
                            ['name' => 'status', 'type' => 'varchar'],
                            ['name' => 'created_at', 'type' => 'timestamp'],
                        ],
                    ]],
                    'responders' => [[
                        'table' => 'responders',
                        'resource' => 'responders',
                        'columns' => [
                            ['name' => 'id', 'type' => 'integer'],
                            ['name' => 'role', 'type' => 'varchar'],
                        ],
                    ]],
                ];

                return $schemas[$resource ?? 'incidents'] ?? [];
            }

            public function previewRows(string $resource, array $filters = [], int $limit = 50): array
            {
                return array_slice($this->incidentRows(), 0, $limit);
            }

            public function paginateRows(string $resource, array $filters = [], int $page = 1, int $perPage = 25): array
            {
                $rows = $resource === 'incidents'
                    ? $this->filterRows($this->incidentRows(), $filters)
                    : [['id' => 1, 'role' => 'Patrol']];

                return [
                    'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => count($rows),
                        'last_page' => (int) ceil(count($rows) / $perPage),
                        'from' => 1,
                        'to' => min($perPage, count($rows)),
                    ],
                ];
            }

            public function countRecords(string $resource, array $filters = []): int|float
            {
                if ($resource === 'responders') {
                    return 1;
                }

                return count($this->filterRows($this->incidentRows(), $filters));
            }

            public function aggregateByGroup(
                string $resource,
                string $groupColumn,
                string $metric = 'count',
                ?string $valueColumn = null,
                array $filters = [],
                int $limit = 10,
            ): array {
                $rows = $this->filterRows($this->incidentRows(), $filters);
                $buckets = [];

                foreach ($rows as $row) {
                    $label = $row[$groupColumn] ?? 'Unknown';
                    $label = $label === null || $label === '' ? 'Unknown' : (string) $label;
                    $buckets[$label] = ($buckets[$label] ?? 0) + 1;
                }

                arsort($buckets);

                return collect($buckets)
                    ->map(fn(int $value, string $label) => ['label' => $label, 'value' => $value])
                    ->values()
                    ->take($limit)
                    ->all();
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
                $rows = $this->filterRows($this->incidentRows(), array_merge($filters, ['date_column' => $dateColumn]));
                $buckets = [];

                foreach ($rows as $row) {
                    $label = $this->dateBucketLabel((string) ($row[$dateColumn] ?? ''), $period);
                    $buckets[$label] = ($buckets[$label] ?? 0) + 1;
                }

                ksort($buckets);

                return collect($buckets)
                    ->map(fn(int $value, string $label) => ['label' => $label, 'value' => $value])
                    ->values()
                    ->take($limit)
                    ->all();
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

            private function incidentRows(): array
            {
                return [
                    ['id' => 1, 'classification' => 'Security', 'status' => 'Open', 'created_at' => '2026-01-01T10:00:00Z'],
                    ['id' => 2, 'classification' => 'Maintenance', 'status' => 'Closed', 'created_at' => '2026-01-10T10:00:00Z'],
                    ['id' => 3, 'classification' => 'Security', 'status' => 'Open', 'created_at' => '2026-01-15T10:00:00Z'],
                    ['id' => 4, 'classification' => 'Emergency', 'status' => 'Escalated', 'created_at' => '2026-01-20T10:00:00Z'],
                    ['id' => 5, 'classification' => 'Maintenance', 'status' => 'Closed', 'created_at' => '2026-01-25T10:00:00Z'],
                ];
            }

            private function filterRows(array $rows, array $filters): array
            {
                return array_values(array_filter($rows, function (array $row) use ($filters) {
                    $dateColumn = $filters['date_column'] ?? null;

                    if (is_string($dateColumn) && isset($row[$dateColumn])) {
                        $timestamp = strtotime((string) $row[$dateColumn]);

                        if (!empty($filters['from']) && $timestamp < strtotime((string) $filters['from'])) {
                            return false;
                        }

                        if (!empty($filters['to']) && $timestamp > strtotime((string) $filters['to'] . ' 23:59:59')) {
                            return false;
                        }
                    }

                    foreach ((array) ($filters['equals'] ?? []) as $clause) {
                        $column = $clause['column'] ?? null;

                        if (!is_string($column) || !array_key_exists('value', $clause)) {
                            continue;
                        }

                        if (($row[$column] ?? null) !== $clause['value']) {
                            return false;
                        }
                    }

                    return true;
                }));
            }

            private function dateBucketLabel(string $value, string $period): string
            {
                $date = Carbon::parse($value);

                return match ($period) {
                    'monthly' => $date->copy()->startOfMonth()->toDateString(),
                    'weekly' => sprintf('%d-W%02d', $date->isoWeekYear, $date->isoWeek),
                    'annual' => $date->copy()->startOfYear()->toDateString(),
                    'semiannual' => $date->format('Y') . '-H' . ($date->month <= 6 ? '1' : '2'),
                    default => $date->toDateString(),
                };
            }
        };
    }
}
