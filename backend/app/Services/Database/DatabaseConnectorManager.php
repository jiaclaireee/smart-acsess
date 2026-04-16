<?php

namespace App\Services\Database;

use App\Models\ConnectedDatabase;
use App\Services\Database\Contracts\DatabaseConnector;

class DatabaseConnectorManager
{
    public function __construct(protected DatabaseDriverRegistry $registry)
    {
    }

    public function for(ConnectedDatabase $database): DatabaseConnector
    {
        $connectorClass = $this->registry->connectorClass($database->type);

        if ($connectorClass === null) {
            throw new DatabaseConnectorException(
                "No connector is registered for database type [{$database->type}]. Register it in config/database_connectors.php to enable test, schema, and metrics support."
            );
        }

        return new $connectorClass($database);
    }
}
