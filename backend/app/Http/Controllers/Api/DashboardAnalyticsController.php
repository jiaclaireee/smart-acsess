<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ValidatesDashboardFilters;
use App\Http\Controllers\Controller;
use App\Models\ConnectedDatabase;
use App\Services\AuditTrailService;
use App\Services\Database\DatabaseConnectorException;
use App\Services\Database\DatabaseConnectorManager;
use App\Services\DatabaseReportingService;
use Illuminate\Http\Request;
use Throwable;

class DashboardAnalyticsController extends Controller
{
    use ValidatesDashboardFilters;

    public function report(
        Request $request,
        DatabaseConnectorManager $manager,
        DatabaseReportingService $reportingService,
    ) {
        $data = $this->validateDashboardFilters($request);

        $database = ConnectedDatabase::findOrFail($data['db_id']);
        $data['resource'] = $data['resource']
            ?? $data['table']
            ?? $data['collection']
            ?? null;

        try {
            $report = $reportingService->buildReport($database, $manager->for($database), $data);

            app(AuditTrailService::class)->record(
                $request,
                'Dashboard',
                'Set Dashboard Filter',
                'Generated dashboard results using the selected filters.',
                [
                    'database_id' => $database->id,
                    'database_name' => $database->name,
                    'resource_type' => $report['resource_type'] ?? 'table',
                    'resource' => $data['resource'],
                    'selected_table' => $data['table'] ?? (($report['resource_type'] ?? 'table') === 'table' ? $data['resource'] : null),
                    'selected_collection' => $data['collection'] ?? (($report['resource_type'] ?? 'table') === 'collection' ? $data['resource'] : null),
                    'from' => $data['from'] ?? null,
                    'to' => $data['to'] ?? null,
                    'period' => $data['period'] ?? 'none',
                    'graph_type' => $data['graph_type'] ?? 'table',
                    'date_column' => $data['date_column'] ?? null,
                    'group_column' => $data['group_column'] ?? null,
                    'sort_by' => $data['sort_by'] ?? null,
                    'sort_direction' => $data['sort_direction'] ?? null,
                    'page' => $data['page'] ?? 1,
                    'per_page' => $data['per_page'] ?? 25,
                    'returned_resource' => $report['selected_resource'] ?? null,
                    'chart_mode' => $report['chart']['meta']['mode'] ?? null,
                ],
                ConnectedDatabase::class,
                $database->id,
            );

            return response()->json($report);
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to build the dashboard report for the selected database connection.',
            ], 422);
        }
    }

    public function drilldown(
        Request $request,
        DatabaseConnectorManager $manager,
        DatabaseReportingService $reportingService,
    ) {
        $data = $this->validateDashboardDrilldown($request);

        $database = ConnectedDatabase::findOrFail($data['db_id']);
        $data['resource'] = $data['resource']
            ?? $data['table']
            ?? $data['collection']
            ?? null;

        try {
            return response()->json(
                $reportingService->buildDrilldown($database, $manager->for($database), $data)
            );
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to load the drill-down rows for the selected chart value.',
            ], 422);
        }
    }
}
