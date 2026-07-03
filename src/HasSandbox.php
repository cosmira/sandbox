<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Cosmira\Sandbox\Support\SandboxTableSynchronizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds sandbox table switching and synchronization to an Eloquent model.
 */
trait HasSandbox
{
    /**
     * The suffix appended to active table names for sandbox tables.
     */
    protected static string $sandboxTablePostfix = '_sb';

    /**
     * The primary key used when matching active and sandbox rows.
     *
     * @var string|array<int, string>|null
     */
    protected static string|array|null $sandboxPrimaryKey = null;

    /**
     * The column used to detect changed rows during sync.
     */
    protected static ?string $sandboxTrackChangeColumn = 'change_date';

    /**
     * The columns copied between active and sandbox tables.
     *
     * @var array<int, string>|null
     */
    protected static ?array $sandboxSyncColumns = null;

    /**
     * Get the column used to detect changed rows during sync.
     */
    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return static::$sandboxTrackChangeColumn;
    }

    /**
     * Indicates if queries should target the sandbox table.
     */
    protected static bool $useSandboxTable = false;

    /**
     * Get the active table name for the model.
     */
    public function getActiveTable(): string
    {
        return parent::getTable();
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return $this->getTableForQuery();
    }

    /**
     * Get the table that should be used for the current query mode.
     */
    public function getTableForQuery(): string
    {
        return static::$useSandboxTable ? $this->getSandboxTable() : $this->getActiveTable();
    }

    /**
     * Get the suffix used for sandbox tables.
     */
    public function getSandboxTablePostfix(): string
    {
        return static::$sandboxTablePostfix;
    }

    /**
     * Get the primary key used to match sandbox rows.
     *
     * @return string|array<int, string>
     */
    public function getSandboxPrimaryKey(): string|array
    {
        return static::$sandboxPrimaryKey ?? $this->getKeyName();
    }

    /**
     * Get the primary key columns as an array.
     *
     * @return array<int, string>
     */
    private function getSandboxPrimaryKeyColumns(): array
    {
        $key = $this->getSandboxPrimaryKey();

        return is_array($key) ? $key : [$key];
    }

    /**
     * Get the sandbox table name for the model.
     */
    public function getSandboxTable(): string
    {
        return $this->getActiveTable().$this->getSandboxTablePostfix();
    }

    /**
     * Switch model queries to the sandbox table.
     */
    public static function useSandboxTable(): void
    {
        static::$useSandboxTable = true;
    }

    /**
     * Switch model queries to the active table.
     */
    public static function useActiveTable(): void
    {
        static::$useSandboxTable = false;
    }

    /**
     * Determine if model queries currently target the sandbox table.
     */
    public static function isUsingSandboxTable(): bool
    {
        return static::$useSandboxTable;
    }

    /**
     * Run the callback while model queries target the active table.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback
     *
     * @return TReturn
     */
    public static function withoutSandbox(callable $callback): mixed
    {
        return static::usingTableState(false, $callback);
    }

    /**
     * Run the callback while model queries target the sandbox table.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback
     *
     * @return TReturn
     */
    public static function withSandbox(callable $callback): mixed
    {
        return static::usingTableState(true, $callback);
    }

    /**
     * Scope the query to the sandbox table.
     */
    protected function scopeSandbox(Builder $query): Builder
    {
        return $query->from($this->getSandboxTable());
    }

    /**
     * Scope the query to the active table.
     */
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

        static::syncTables(
            sourceTable: $instance->getActiveTable(),
            targetTable: $instance->getSandboxTable(),
            sourceAlias: 't',
            targetAlias: 'sb',
        );
    }

    /**
     * Sync the sandbox table into the active table.
     */
    public static function syncIntoActive(): void
    {
        $instance = new static();

        static::syncTables(
            sourceTable: $instance->getSandboxTable(),
            targetTable: $instance->getActiveTable(),
            sourceAlias: 'sb',
            targetAlias: 't',
        );
    }

    /**
     * Sync rows from one configured model table to another.
     */
    private static function syncTables(
        string $sourceTable,
        string $targetTable,
        string $sourceAlias,
        string $targetAlias,
    ): void {
        $instance = new static();

        DB::transaction(function () use (
            $sourceTable,
            $targetTable,
            $sourceAlias,
            $targetAlias,
            $instance,
        ): void {
            static::synchronizer()->sync(
                sourceTable: $sourceTable,
                targetTable: $targetTable,
                keyColumns: $instance->getSandboxPrimaryKeyColumns(),
                columns: $instance->getSandboxSyncColumns(),
                changeColumn: static::getSandboxTrackChangeColumn(),
                sourceAlias: $sourceAlias,
                targetAlias: $targetAlias,
            );
        });
    }

    /**
     * Create the table synchronizer for sandbox data.
     */
    private static function synchronizer(): SandboxTableSynchronizer
    {
        return new SandboxTableSynchronizer();
    }

    /**
     * Run the callback with a temporary table state.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback
     *
     * @return TReturn
     */
    private static function usingTableState(bool $useSandbox, callable $callback): mixed
    {
        $previousState = static::$useSandboxTable;
        static::$useSandboxTable = $useSandbox;

        try {
            return $callback();
        } finally {
            static::$useSandboxTable = $previousState;
        }
    }

    /**
     * Get the columns that may be written during sandbox synchronization.
     *
     * @return array<int, string>
     */
    public function getSandboxWritableColumns(): array
    {
        return $this->getSandboxSyncColumns();
    }

    /**
     * Get the columns copied during sandbox synchronization.
     *
     * @return array<int, string>
     */
    protected function getSandboxSyncColumns(): array
    {
        return static::$sandboxSyncColumns ?? Schema::getColumnListing($this->getActiveTable());
    }

    /**
     * Determine if the model supports sandbox synchronization.
     */
    public static function supportsSandboxSync(): bool
    {
        return true;
    }
}
