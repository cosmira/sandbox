<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\SandboxCopyRules;
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
        ?string $parentColumn = null,
        ?SandboxCopyRules $rules = null,
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
        $updateColumns = $rules?->updateColumns ?? $columns;
        if (array_diff($updateColumns, $columns) !== []) {
            throw new InvalidArgumentException('Sandbox update columns must exist in the copied table.');
        }

        $this->connection->transaction(function () use (
            $sourceTable, $targetTable, $keyColumns, $columns, $targetAlias, $sourceAlias, $parentColumn,
            $rules, $updateColumns,
        ): void {
            if ($parentColumn === null) {
                $this->deleteMissing($targetTable, $sourceTable, $keyColumns, $rules);
            }
            if ($parentColumn !== null) {
                $this->insertTreeMissing($targetTable, $sourceTable, $keyColumns, $columns, $parentColumn, $rules);
            }
            if ($updateColumns !== []) {
                $this->updateExisting(
                    $targetTable, $sourceTable, $keyColumns,
                    array_values(array_unique([...$keyColumns, ...$updateColumns])),
                    $targetAlias, $sourceAlias, $rules,
                );
            }
            if ($parentColumn === null) {
                $this->insertMissing($targetTable, $sourceTable, $keyColumns, $columns, $rules);
            } else {
                $this->deleteMissing($targetTable, $sourceTable, $keyColumns, $rules);
            }
        });
    }

    /**
     * Insert successive tree levels without depending on source row order or deferred foreign keys.
     *
     * @param list<string> $keyColumns
     * @param list<string> $columns
     */
    private function insertTreeMissing(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
        string $parentColumn,
        ?SandboxCopyRules $rules,
    ): void {
        if (count($keyColumns) !== 1 || ! in_array($parentColumn, $columns, true)) {
            throw new InvalidArgumentException('Sandbox tree synchronization requires one key and a selected parent column.');
        }

        $missing = $this->connection->table($sourceTable)
            ->whereNotExists(fn (QueryBuilder $query) => $this->matchingRowSubquery(
                $query, $targetTable, $sourceTable, $keyColumns,
            ));
        if ($rules?->inserts !== null) {
            ($rules->inserts)($missing);
        }

        while ((clone $missing)->exists()) {
            $ready = (clone $missing)->where(function (QueryBuilder $query) use (
                $sourceTable, $targetTable, $parentColumn, $keyColumns,
            ): void {
                $query->whereNull($sourceTable.'.'.$parentColumn)
                    ->orWhereColumn($sourceTable.'.'.$parentColumn, $sourceTable.'.'.$keyColumns[0])
                    ->orWhereExists(fn (QueryBuilder $parent) => $parent
                        ->select($targetTable.'.'.$keyColumns[0])->from($targetTable)
                        ->whereColumn($targetTable.'.'.$keyColumns[0], $sourceTable.'.'.$parentColumn));
            })->select($columns);

            if ($this->insertChunked($targetTable, $ready->cursor()) === 0) {
                throw new SandboxException('Sandbox tree contains an unresolved parent or a cycle.');
            }
        }
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
        ?SandboxCopyRules $rules,
    ): void {
        $query = $this->connection->table($targetTable)
            ->whereNotExists(fn (QueryBuilder $query) => $this->matchingRowSubquery(
                $query,
                matchTable: $sourceTable,
                currentTable: $targetTable,
                keyColumns: $keyColumns,
            ));
        if ($rules?->deletes !== null) {
            ($rules->deletes)($query);
        }
        $query->delete();
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
        ?SandboxCopyRules $rules,
    ): void {
        foreach ($this->matchingRows(
            $targetTable,
            $sourceTable,
            $keyColumns,
            $columns,
            $targetAlias,
            $sourceAlias,
            $rules,
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
        ?SandboxCopyRules $rules,
    ): iterable {
        $query = $this->connection->table($sourceTable.' as '.$sourceAlias)
            ->join(
                $targetTable.' as '.$targetAlias,
                fn (JoinClause $join) => $this->joinOnKeys(
                    $join,
                    $keyColumns,
                    $targetAlias,
                    $sourceAlias,
                ),
            )
            ->select($this->selectColumns($sourceAlias, $columns));
        if ($rules?->updates !== null) {
            ($rules->updates)($query);
        }

        return $query->cursor();
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
        ?SandboxCopyRules $rules,
    ): void {
        $query = $this->connection->table($sourceTable)
            ->whereNotExists(fn (QueryBuilder $query) => $this->matchingRowSubquery(
                $query,
                matchTable: $targetTable,
                currentTable: $sourceTable,
                keyColumns: $keyColumns,
            ))
            ->select($columns);
        if ($rules?->inserts !== null) {
            ($rules->inserts)($query);
        }

        $this->insertChunked($targetTable, $query->cursor());
    }

    /**
     * Insert rows using portable batches.
     *
     * @param iterable<int, object> $rows
     */
    private function insertChunked(string $table, iterable $rows): int
    {
        $chunk = [];
        $inserted = 0;

        foreach ($rows as $row) {
            $chunk[] = (array) $row;
            $inserted++;

            if (count($chunk) === self::INSERT_CHUNK_SIZE) {
                $this->connection->table($table)->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->connection->table($table)->insert($chunk);
        }

        return $inserted;
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
