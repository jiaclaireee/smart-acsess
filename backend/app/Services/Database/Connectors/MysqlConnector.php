<?php

namespace App\Services\Database\Connectors;

class MysqlConnector extends AbstractSqlConnector
{
    protected function driver(): string
    {
        return 'mysql';
    }

    protected function defaultPort(): int
    {
        return 3306;
    }

    protected function listResourcesSql(): string
    {
        return "
            SELECT table_name AS resource_name
            FROM information_schema.tables
            WHERE table_schema = ?
              AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ";
    }

    protected function listResourcesBindings(): array
    {
        return [$this->database->database_name];
    }

    protected function schemaSql(): string
    {
        return "
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = ?
              AND table_name = ?
            ORDER BY ordinal_position
        ";
    }

    protected function schemaBindings(string $resource): array
    {
        return [$this->database->database_name, $resource];
    }

    protected function bucketExpression(string $dateColumn, string $period): ?string
    {
        $column = $this->wrapIdentifier($dateColumn);

        return match ($period) {
            'daily' => "DATE({$column})",
            'weekly' => "STR_TO_DATE(CONCAT(YEARWEEK({$column}, 1), ' Monday'), '%X%V %W')",
            'monthly' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            'quarterly' => "MAKEDATE(YEAR({$column}), 1) + INTERVAL QUARTER({$column}) - 1 QUARTER",
            'semiannual' => "MAKEDATE(YEAR({$column}), 1) + INTERVAL IF(MONTH({$column}) <= 6, 0, 6) MONTH",
            'annual' => "DATE_FORMAT({$column}, '%Y-01-01')",
            default => null,
        };
    }

    protected function groupLabelExpression(string $wrappedIdentifier): string
    {
        return "CAST({$wrappedIdentifier} AS CHAR)";
    }
}
