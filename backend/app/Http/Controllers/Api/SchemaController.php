<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectedDatabase;
use App\Services\Database\DatabaseConnectorException;
use App\Services\Database\DatabaseConnectorManager;
use Illuminate\Http\Request;
use Throwable;

class SchemaController extends Controller
{
    public function inspect(Request $request, ConnectedDatabase $database, DatabaseConnectorManager $manager)
    {
        $resource = $request->query('resource')
            ?? $request->query('table')
            ?? $request->query('collection');

        try {
            return response()->json([
                'db' => $database->publicMetadata(),
                'resource_type' => $database->resourceLabel(),
                'schema' => $manager->for($database)->getSchema($resource ? (string) $resource : null),
            ]);
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to inspect the saved database connection.',
            ], 422);
        }
    }
}
