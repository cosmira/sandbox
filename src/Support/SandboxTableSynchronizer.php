<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\SandboxCopyRules;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Synchronizes rows between active and sandbox tables.
 */
class SandboxTableSynchronizer
{
    /**
     * Connection on which all copy queries and transactions execute.
     */
    private readonly ConnectionInterface $connection;

    /**
     * Use the supplied copy connection or the application default.
     */
    public function __construct(?ConnectionInterface $connection = null)
    {
        $this->connection = $connection ?? DB::connection();
    }

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
            throw new InvalidArgumentException(
                'Sandbox synchronization requires at least one key column.',
            );
        }
        $copyColumns = $columns ?: $this->columnsFrom($sourceTable);
        if ($copyColumns === []) {
            return;
        }
        if ($changeColumn !== null) {
            $this->ensureChangeColumn($sourceTable, $copyColumns, $changeColumn);
        }
        $copyColumns = array_values(array_unique([...$keyColumns, ...$copyColumns]));
        $updateColumns = $rules->updateColumns ?? $copyColumns;
        if (array_diff($updateColumns, $copyColumns) !== []) {
            throw new InvalidArgumentException(
                'Sandbox update columns must exist in the copied table.',
            );
        }

        $this->connection->transaction(function () use (
            $sourceTable, $targetTable, $keyColumns, $copyColumns,
            $targetAlias, $sourceAlias, $parentColumn,
            $rules, $updateColumns,
        ): void {
            if ($parentColumn === null) {
                $this->deleteMissing($targetTable, $sourceTable, $keyColumns, $rules);
            }
            if ($parentColumn !== null) {
                $this->insertTreeMissing(
                    $targetTable, $sourceTable, $keyColumns, $copyColumns, $parentColumn, $rules,
                );
            }
            if ($updateColumns !== []) {
                $this->updateExisting(
                    $targetTable, $sourceTable, $keyColumns,
                    array_values(array_unique([...$keyColumns, ...$updateColumns])),
                    $targetAlias, $sourceAlias, $rules,
                );
            }
            if ($parentColumn === null) {
                $this->insertMissing($targetTable, $sourceTable, $keyColumns, $copyColumns, $rules);

                return;
            }
            $this->deleteMissing($targetTable, $sourceTable, $keyColumns, $rules);
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
            throw new InvalidArgumentException(
                'Sandbox tree synchronization requires one key and a selected parent column.',
            );
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
                        ->whereColumn(
                            $targetTable.'.'.$keyColumns[0], $sourceTable.'.'.$parentColumn,
                        ));
            })->select($columns);

            $inserted = $this->connection->table($targetTable)->insertUsing($columns, $ready);
            if ($inserted === 0) {
                throw new SandboxException(
                    'Sandbox tree contains an unresolved parent or a cycle.',
                );
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
        $source = $this->connection->table($sourceTable, $sourceAlias);
        $this->whereKeysMatch($source, $sourceAlias, $targetAlias, $keyColumns);
        if ($rules?->updates !== null) {
            ($rules->updates)($source);
        }

        $values = [];
        foreach (array_diff($columns, $keyColumns) as $column) {
            $values[$column] = (clone $source)->select($sourceAlias.'.'.$column);
        }

        if ($values !== []) {
            $this->connection->table($targetTable, $targetAlias)
                ->whereExists((clone $source)->selectRaw('1'))
                ->update($values);
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

        $this->connection->table($targetTable)->insertUsing($columns, $query);
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
        return SandboxColumns::writable($this->connection->getSchemaBuilder(), $table);
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
}
