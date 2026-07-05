<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Synchronizes rows between active and sandbox tables.
 */
class SandboxTableSynchronizer
{
    /**
     * The number of rows inserted per portable insert batch.
     */
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * Synchronize source rows into the target table.
     *
     * @param array<int, string> $keyColumns
     * @param array<int, string> $columns
     */
    public function sync(
        string $sourceTable,
        string $targetTable,
        array $keyColumns,
        array $columns,
        ?string $changeColumn,
        string $sourceAlias = 'source',
        string $targetAlias = 'target',
    ): void {
        $this->deleteMissing($targetTable, $sourceTable, $keyColumns);

        $columns = $columns ?: $this->columnsFrom($sourceTable);
        if ($columns === []) {
            return;
        }

        $this->syncExisting(
            targetTable: $targetTable,
            sourceTable: $sourceTable,
            keyColumns: $keyColumns,
            columns: $columns,
            changeColumn: $changeColumn,
            targetAlias: $targetAlias,
            sourceAlias: $sourceAlias,
        );
        $this->insertMissing($targetTable, $sourceTable, $keyColumns, $columns);
    }

    /**
     * Delete target rows that no longer exist in the source table.
     *
     * @param array<int, string> $keyColumns
     */
    private function deleteMissing(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
    ): void {
        DB::table($targetTable)
            ->whereNotExists(fn (QueryBuilder $query) => $this->matchingRowSubquery(
                $query,
                matchTable: $sourceTable,
                currentTable: $targetTable,
                keyColumns: $keyColumns,
            ))
            ->delete();
    }

    /**
     * Synchronize existing target rows from source rows.
     *
     * @param array<int, string> $keyColumns
     * @param array<int, string> $columns
     */
    private function syncExisting(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
        ?string $changeColumn,
        string $targetAlias,
        string $sourceAlias,
    ): void {
        if ($changeColumn === null) {
            $this->replaceExisting($targetTable, $sourceTable, $keyColumns);

            return;
        }

        $this->ensureChangeColumn($sourceTable, $columns, $changeColumn);

        $this->updateChanged(
            targetTable: $targetTable,
            sourceTable: $sourceTable,
            keyColumns: $keyColumns,
            columns: $columns,
            changeColumn: $changeColumn,
            targetAlias: $targetAlias,
            sourceAlias: $sourceAlias,
        );
    }

    /**
     * Delete target rows before replacing them from the source table.
     *
     * @param array<int, string> $keyColumns
     */
    private function replaceExisting(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
    ): void {
        DB::table($targetTable)
            ->whereExists(fn (QueryBuilder $query) => $this->matchingRowSubquery(
                $query,
                matchTable: $sourceTable,
                currentTable: $targetTable,
                keyColumns: $keyColumns,
            ))
            ->delete();
    }

    /**
     * Update target rows whose tracked column differs from the source table.
     *
     * @param array<int, string> $keyColumns
     * @param array<int, string> $columns
     */
    private function updateChanged(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
        string $changeColumn,
        string $targetAlias,
        string $sourceAlias,
    ): void {
        foreach ($this->changedRows(
            $targetTable,
            $sourceTable,
            $keyColumns,
            $columns,
            $changeColumn,
            $targetAlias,
            $sourceAlias,
        ) as $row) {
            $attributes = (array) $row;
            $keys = [];
            $values = $attributes;

            foreach ($keyColumns as $keyColumn) {
                $keys[$keyColumn] = $attributes[$keyColumn];
                unset($values[$keyColumn]);
            }

            if ($values !== []) {
                DB::table($targetTable)->where($keys)->update($values);
            }
        }
    }

    /**
     * Get source rows that should replace changed target rows.
     *
     * @param array<int, string> $keyColumns
     * @param array<int, string> $columns
     *
     * @return iterable<int, object>
     */
    private function changedRows(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
        string $changeColumn,
        string $targetAlias,
        string $sourceAlias,
    ): iterable {
        return DB::table($sourceTable.' as '.$sourceAlias)
            ->join(
                $targetTable.' as '.$targetAlias,
                fn (JoinClause $join) => $this->joinOnKeys(
                    $join,
                    $keyColumns,
                    $targetAlias,
                    $sourceAlias,
                ),
            )
            ->where(fn (QueryBuilder $query) => $this->whereTrackedColumnDiffers(
                $query,
                $targetAlias,
                $sourceAlias,
                $changeColumn,
            ))
            ->select($this->selectColumns($sourceAlias, $columns))
            ->cursor();
    }

    /**
     * Add join conditions for the configured sync keys.
     *
     * @param array<int, string> $keyColumns
     */
    private function joinOnKeys(
        JoinClause $join,
        array $keyColumns,
        string $targetAlias,
        string $sourceAlias,
    ): void {
        foreach ($keyColumns as $col) {
            $join->on($targetAlias.'.'.$col, '=', $sourceAlias.'.'.$col);
        }
    }

    /**
     * Apply a null-safe condition for changed tracked columns.
     */
    private function whereTrackedColumnDiffers(
        QueryBuilder $query,
        string $targetAlias,
        string $sourceAlias,
        string $changeColumn,
    ): void {
        $targetColumn = $targetAlias.'.'.$changeColumn;
        $sourceColumn = $sourceAlias.'.'.$changeColumn;

        $query
            ->whereColumn($targetColumn, '!=', $sourceColumn)
            ->orWhere(function (QueryBuilder $query) use ($targetColumn, $sourceColumn): void {
                $query->whereNull($targetColumn)->whereNotNull($sourceColumn);
            })
            ->orWhere(function (QueryBuilder $query) use ($targetColumn, $sourceColumn): void {
                $query->whereNotNull($targetColumn)->whereNull($sourceColumn);
            });
    }

    /**
     * Insert source rows that are missing from the target table.
     *
     * @param array<int, string> $keyColumns
     * @param array<int, string> $columns
     */
    private function insertMissing(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
    ): void {
        $rows = DB::table($sourceTable)
            ->whereNotExists(fn (QueryBuilder $query) => $this->matchingRowSubquery(
                $query,
                matchTable: $targetTable,
                currentTable: $sourceTable,
                keyColumns: $keyColumns,
            ))
            ->select($columns)
            ->cursor();

        $this->insertChunked($targetTable, $rows);
    }

    /**
     * Insert rows using portable batches.
     *
     * @param iterable<int, object> $rows
     */
    private function insertChunked(string $table, iterable $rows): void
    {
        $chunk = [];

        foreach ($rows as $row) {
            $chunk[] = (array) $row;

            if (count($chunk) === self::INSERT_CHUNK_SIZE) {
                DB::table($table)->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * Ensure the tracked column is available on selected source rows.
     *
     * @param array<int, string> $columns
     *
     * @throws SandboxException
     */
    private function ensureChangeColumn(
        string $sourceTable,
        array $columns,
        string $changeColumn,
    ): void {
        throw_unless(
            in_array($changeColumn, $columns, true),
            SandboxException::class,
            sprintf(
                'Sandbox sync column [%s] does not exist on [%s].',
                $changeColumn,
                $sourceTable,
            ),
            SandboxException::CODE_SYNC_COLUMN_MISSING,
        );
    }

    /**
     * Get the columns defined on the table schema.
     *
     * @return array<int, string>
     */
    private function columnsFrom(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    /**
     * Select a harmless column for an exists subquery.
     *
     * @param array<int, string> $keyColumns
     */
    private function matchingRowSubquery(
        QueryBuilder $query,
        string $matchTable,
        string $currentTable,
        array $keyColumns,
    ): void {
        $query->select($matchTable.'.'.$keyColumns[0])->from($matchTable);

        $this->whereKeysMatch($query, $matchTable, $currentTable, $keyColumns);
    }

    /**
     * Add key comparisons between two table references.
     *
     * @param array<int, string> $keyColumns
     */
    private function whereKeysMatch(
        QueryBuilder $query,
        string $leftTable,
        string $rightTable,
        array $keyColumns,
    ): void {
        foreach ($keyColumns as $col) {
            $query->whereColumn($leftTable.'.'.$col, $rightTable.'.'.$col);
        }
    }

    /**
     * Qualify source columns and keep stable output names.
     *
     * @param array<int, string> $columns
     *
     * @return array<int, string>
     */
    private function selectColumns(string $table, array $columns): array
    {
        return array_map(
            fn (string $col): string => $table.'.'.$col.' as '.$col,
            $columns,
        );
    }
}
