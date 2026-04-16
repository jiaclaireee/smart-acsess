<?php

namespace App\Services\Database\Connectors;

class SqlServerConnector extends AbstractSqlConnector
{
    protected function driver(): string
    {
        return 'sqlsrv';
    }

    protected function defaultPort(): int
    {
        return 1433;
    }

    protected function listResourcesSql(): string
    {
        return "
            SELECT TABLE_NAME AS resource_name
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_TYPE = 'BASE TABLE'
              AND TABLE_CATALOG = ?
              AND TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME
        ";
    }

    protected function listResourcesBindings(): array
    {
        return [$this->database->database_name, $this->schemaName()];
    }

    protected function schemaSql(): string
    {
        return "
            SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_CATALOG = ?
              AND TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ";
    }

    protected function schemaBindings(string $resource): array
    {
        return [$this->database->database_name, $this->schemaName(), $resource];
    }

    protected function bucketExpression(string $dateColumn, string $period): ?string
    {
        $column = $this->wrapIdentifier($dateColumn);

        return match ($period) {
            'daily' => "CAST({$column} AS date)",
            'weekly' => "DATEADD(week, DATEDIFF(week, 0, {$column}), 0)",
            'monthly' => "DATEFROMPARTS(YEAR({$column}), MONTH({$column}), 1)",
            'quarterly' => "DATEFROMPARTS(YEAR({$column}), ((DATEPART(quarter, {$column}) - 1) * 3) + 1, 1)",
            'semiannual' => "DATEFROMPARTS(YEAR({$column}), CASE WHEN MONTH({$column}) <= 6 THEN 1 ELSE 7 END, 1)",
            'annual' => "DATEFROMPARTS(YEAR({$column}), 1, 1)",
            default => null,
        };
    }

    protected function groupLabelExpression(string $wrappedIdentifier): string
    {
        return "CAST({$wrappedIdentifier} AS nvarchar(255))";
    }

    private function schemaName(): string
    {
        return (string) ($this->database->extra_config['schema'] ?? 'dbo');
    }
}
