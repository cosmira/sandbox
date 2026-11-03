<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Illuminate\Database\Schema\Builder;

/**
 * Discovers columns that can be written during automatic sandbox synchronization.
 */
final class SandboxColumns
{
    /**
     * Exclude generated values and use visible schema metadata instead of driver column listings.
     *
     * @return list<string>
     */
    public static function writable(Builder $schema, string $table): array
    {
        $connection = $schema->getConnection();
        if ($connection->getDriverName() === 'oracle') {
            [$owner, $tableName] = str_contains($table, '.')
                ? explode('.', $table, 2)
                : [null, $table];
            $columns = $connection->selectFromWriteConnection(
                'select lower(column_name) as name from all_tab_cols '
                ."where owner = coalesce(upper(?), sys_context('USERENV', 'CURRENT_SCHEMA')) "
                .'and table_name = ? '
                ."and hidden_column = 'NO' and virtual_column = 'NO' order by column_id",
                [
                    $owner,
                    strtoupper($connection->getTablePrefix().$tableName),
                ],
            );

            return array_column($columns, 'name');
        }

        $columns = array_filter(
            $schema->getColumns($table),
            static fn (array $column): bool => ($column['generation'] ?? null) === null,
        );

        return array_column($columns, 'name');
    }
}
