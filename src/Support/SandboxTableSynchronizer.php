<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Synchronizes rows between active and sandbox tables.
 */
class SandboxTableSynchronizer
{
    private readonly ConnectionInterface $connection;

    public function __construct(?ConnectionInterface $connection = null)
    {
        $this->connection = $connection ?? DB::connection();
    }

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
        if ($keyColumns === []) {
            throw new InvalidArgumentException('Sandbox synchronization requires at least one key column.');
        }
        $columns = $columns ?: $this->columnsFrom($sourceTable);
        if ($columns === []) {
            return;
        }
        if ($changeColumn !== null) {
            $this->ensureChangeColumn($sourceTable, $columns, $changeColumn);
        }
        $columns = array_values(array_unique([...$keyColumns, ...$columns]));

        $this->connection->transaction(function () use (
            $sourceTable, $targetTable, $keyColumns, $columns, $targetAlias, $sourceAlias,
        ): void {
            $this->deleteMissing($targetTable, $sourceTable, $keyColumns);
            $this->updateExisting($targetTable, $sourceTable, $keyColumns, $columns, $targetAlias, $sourceAlias);
            $this->insertMissing($targetTable, $sourceTable, $keyColumns, $columns);
        });
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
        $this->connection->table($targetTable)
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
    private function updateExisting(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
        string $targetAlias,
        string $sourceAlias,
    ): void {
        foreach ($this->matchingRows(
            $targetTable,
            $sourceTable,
            $keyColumns,
            $columns,
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
                $this->connection->table($targetTable)->where($keys)->update($values);
            }
        }
    }

    /**
     * Read matching rows regardless of timestamp precision or database collation.
     *
     * @param array<int, string> $keyColumns
     * @param array<int, string> $columns
     *
     * @return iterable<int, object>
     */
    private function matchingRows(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
        string $targetAlias,
        string $sourceAlias,
    ): iterable {
        return $this->connection->table($sourceTable.' as '.$sourceAlias)
            ->join(
                $targetTable.' as '.$targetAlias,
                fn (JoinClause $join) => $this->joinOnKeys(
                    $join,
                    $keyColumns,
                    $targetAlias,
                    $sourceAlias,
                ),
            )
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
        $rows = $this->connection->table($sourceTable)
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
                $this->connection->table($table)->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->connection->table($table)->insert($chunk);
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
        return $this->connection->getSchemaBuilder()->getColumnListing($table);
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
