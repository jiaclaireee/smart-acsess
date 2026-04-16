<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectedDatabase;
use App\Services\Database\DatabaseConnectorException;
use App\Services\Database\DatabaseConnectorManager;
use Illuminate\Http\Request;
use Throwable;

class MetricsController extends Controller
{
    public function run(Request $request, DatabaseConnectorManager $manager)
    {
        $data = $request->validate([
            'db_id' => ['required','integer','exists:connected_databases,id'],
            'table' => ['nullable','string','max:200'],
            'resource' => ['nullable','string','max:200'],
            'metric' => ['required','in:count,sum,avg'],
            'value_column' => ['nullable','string','max:200'],
            'date_column' => ['nullable','string','max:200'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'period' => ['nullable','in:none,daily,weekly,monthly,quarterly,semiannual,annual'],
            'limit' => ['nullable','integer','min:1','max:500'],
        ]);

        $database = ConnectedDatabase::findOrFail($data['db_id']);
        $resource = $data['resource'] ?? $data['table'] ?? null;

        if ($resource === null) {
            return response()->json([
                'message' => 'A table or collection is required.',
            ], 422);
        }

        try {
            return response()->json(
                $manager->for($database)->getAggregateData(
                    $resource,
                    $data['metric'],
                    $data['value_column'] ?? null,
                    $data['date_column'] ?? null,
                    [
                        'from' => $data['from'] ?? null,
                        'to' => $data['to'] ?? null,
                    ],
                    $data['period'] ?? 'none',
                    $data['limit'] ?? 50,
                )
            );
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to run metrics for the selected database connection.',
            ], 422);
        }
    }
}
