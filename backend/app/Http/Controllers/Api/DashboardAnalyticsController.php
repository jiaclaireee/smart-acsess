<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ValidatesDashboardFilters;
use App\Http\Controllers\Controller;
use App\Models\ConnectedDatabase;
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
            return response()->json(
                $reportingService->buildReport($database, $manager->for($database), $data)
            );
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to build the dashboard report for the selected database connection.',
            ], 422);
        }
    }
}
