<?php

declare(strict_types=1);

namespace Packages\Sandbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Facades\DB;

/**
 * Adds sandbox table switching and synchronization to an Eloquent model.
 */
trait HasSandbox
{
    protected static string $sandboxTablePostfix = '_sb';

    /**
     * @var string|array<int, string>|null
     */
    protected static string|array|null $sandboxPrimaryKey = null;

    protected static ?string $sandboxTrackChangeColumn = 'change_date';

    /**
     * Return null to replace matching rows instead of comparing a change column.
     */
    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return static::$sandboxTrackChangeColumn;
    }

    protected static bool $useSandboxTable = false;

    public function getActiveTable(): string
    {
        return parent::getTable();
    }

    public function getTableForQuery(): string
    {
        return static::$useSandboxTable ? $this->getSandboxTable() : $this->getActiveTable();
    }

    public function getSandboxTablePostfix(): string
    {
        return static::$sandboxTablePostfix;
    }

    /**
     * @return string|array<int, string>
     */
    public function getSandboxPrimaryKey(): string|array
    {
        return static::$sandboxPrimaryKey ?? $this->getKeyName();
    }

    /**
     * @return array<int, string>
     */
    private function getSandboxPrimaryKeyColumns(): array
    {
        $key = $this->getSandboxPrimaryKey();

        return is_array($key) ? $key : [$key];
    }

    public function getSandboxTable(): string
    {
        return $this->getActiveTable().$this->getSandboxTablePostfix();
    }

    public static function useSandboxTable(): void
    {
        static::$useSandboxTable = true;
    }

    public static function useActiveTable(): void
    {
        static::$useSandboxTable = false;
    }

    public static function isUsingSandboxTable(): bool
    {
        return static::$useSandboxTable;
    }

    protected function scopeSandbox(Builder $query): Builder
    {
        return $query->from($this->getSandboxTable());
    }

    protected function scopeActive(Builder $query): Builder
    {
        return $query->from($this->getActiveTable());
    }

    /**
     * Sync the active table into the sandbox table.
     */
    public static function syncIntoSandbox(): void
    {
        $instance = new static();
        $activeTable = $instance->getActiveTable();
        $sandboxTable = $instance->getSandboxTable();
        $keyColumns = $instance->getSandboxPrimaryKeyColumns();
        $changeColumn = static::getSandboxTrackChangeColumn();

        DB::transaction(function () use ($instance, $activeTable, $sandboxTable, $keyColumns, $changeColumn): void {
            static::deleteOrphansInTarget($sandboxTable, $activeTable, $keyColumns);
            static::syncUpdatesFromSourceToTarget($instance, $sandboxTable, $activeTable, $keyColumns, $changeColumn, 'sb', 't');
            static::insertMissingIntoTarget($sandboxTable, $activeTable, $keyColumns);
        });
    }

    /**
     * Sync the sandbox table into the active table.
     */
    public static function syncIntoActive(): void
    {
        $instance = new static();
        $activeTable = $instance->getActiveTable();
        $sandboxTable = $instance->getSandboxTable();
        $keyColumns = $instance->getSandboxPrimaryKeyColumns();
        $changeColumn = static::getSandboxTrackChangeColumn();

        DB::transaction(function () use ($instance, $activeTable, $sandboxTable, $keyColumns, $changeColumn): void {
            static::deleteOrphansInTarget($activeTable, $sandboxTable, $keyColumns);
            static::syncUpdatesFromSourceToTarget($instance, $activeTable, $sandboxTable, $keyColumns, $changeColumn, 't', 'sb');
            static::insertMissingIntoTarget($activeTable, $sandboxTable, $keyColumns);
        });
    }

    /**
     * @param array<int, string> $keyColumns
     */
    private static function deleteOrphansInTarget(string $targetTable, string $sourceTable, array $keyColumns): void
    {
        DB::table($targetTable)
            ->whereNotExists(function ($query) use ($sourceTable, $targetTable, $keyColumns): void {
                $query->select(DB::raw(1))->from($sourceTable);
                foreach ($keyColumns as $col) {
                    $query->whereColumn($sourceTable.'.'.$col, $targetTable.'.'.$col);
                }
            })
            ->delete();
    }

    /**
     * @param array<int, string> $keyColumns
     */
    private static function syncUpdatesFromSourceToTarget(
        object $instance,
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        ?string $changeColumn,
        string $targetAlias,
        string $sourceAlias,
    ): void {
        $columns = $instance->getSandboxSyncColumns();
        if ($columns === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        if ($changeColumn === null) {
            static::deleteTargetRowsExistingInSource($targetTable, $sourceTable, $keyColumns, $grammar);
        } else {
            static::updateTargetFromSource($targetTable, $sourceTable, $keyColumns, $columns, $changeColumn, $grammar, $targetAlias, $sourceAlias);
        }
    }

    /**
     * @param array<int, string> $keyColumns
     */
    private static function deleteTargetRowsExistingInSource(string $targetTable, string $sourceTable, array $keyColumns, Grammar $grammar): void
    {
        $keyCondition = implode(' AND ', array_map(
            fn (string $col): string => $grammar->wrap($sourceTable.'.'.$col).' = '.$grammar->wrap($targetTable.'.'.$col),
            $keyColumns,
        ));
        DB::statement('
            DELETE FROM '.$grammar->wrapTable($targetTable).'
            WHERE EXISTS (
                SELECT 1 FROM '.$grammar->wrapTable($sourceTable).' WHERE '.$keyCondition.'
            )
        ');
    }

    /**
     * @param array<int, string> $keyColumns
     * @param array<int, string> $columns
     */
    private static function updateTargetFromSource(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
        string $changeColumn,
        Grammar $grammar,
        string $targetAlias,
        string $sourceAlias,
    ): void {
        $updateSet = [];
        foreach (array_diff($columns, $keyColumns) as $col) {
            $updateSet[$targetAlias.'.'.$col] = DB::raw($grammar->wrap($sourceAlias.'.'.$col));
        }

        $query = DB::table($targetTable.' as '.$targetAlias)
            ->join($sourceTable.' as '.$sourceAlias, function ($join) use ($keyColumns, $targetAlias, $sourceAlias): void {
                foreach ($keyColumns as $col) {
                    $join->on($targetAlias.'.'.$col, '=', $sourceAlias.'.'.$col);
                }
            });
        if (in_array($changeColumn, $columns, true)) {
            $query->whereColumn($targetAlias.'.'.$changeColumn, '!=', $sourceAlias.'.'.$changeColumn);
        }

        $query->update($updateSet);
    }

    /**
     * @param array<int, string> $keyColumns
     */
    private static function insertMissingIntoTarget(string $targetTable, string $sourceTable, array $keyColumns): void
    {
        $notExistsConditions = implode(' AND ', array_map(
            fn (string $col): string => sprintf('%s.%s = %s.%s', $targetTable, $col, $sourceTable, $col),
            $keyColumns,
        ));
        DB::statement("
            INSERT INTO {$targetTable}
            SELECT * FROM {$sourceTable}
            WHERE NOT EXISTS (
                SELECT 1 FROM {$targetTable} WHERE {$notExistsConditions}
            )
        ");
    }

    /**
     * @return array<int, string>
     */
    protected function getSandboxSyncColumns(): array
    {
        $row = DB::table($this->getActiveTable())->first();
        if ($row === null) {
            return [];
        }

        return array_keys((array) $row);
    }

    public static function supportsSandboxSync(): bool
    {
        return true;
    }
}
