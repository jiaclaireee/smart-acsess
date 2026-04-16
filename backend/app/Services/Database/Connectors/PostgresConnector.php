<?php

namespace App\Services\Database\Connectors;

class PostgresConnector extends AbstractSqlConnector
{
    protected function driver(): string
    {
        return 'pgsql';
    }

    protected function defaultPort(): int
    {
        return 5432;
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
        return [$this->schemaName()];
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
        return [$this->schemaName(), $resource];
    }

    protected function bucketExpression(string $dateColumn, string $period): ?string
    {
        $column = $this->wrapIdentifier($dateColumn);

        return match ($period) {
            'daily' => "date_trunc('day', {$column})",
            'weekly' => "date_trunc('week', {$column})",
            'monthly' => "date_trunc('month', {$column})",
            'quarterly' => "date_trunc('quarter', {$column})",
            'semiannual' => "date_trunc('year', {$column}) + (interval '6 months' * floor((extract(month from {$column}) - 1)/6))",
            'annual' => "date_trunc('year', {$column})",
            default => null,
        };
    }

    protected function groupLabelExpression(string $wrappedIdentifier): string
    {
        return "CAST({$wrappedIdentifier} AS text)";
    }

    private function schemaName(): string
    {
        return (string) ($this->database->extra_config['schema'] ?? 'public');
    }
}
