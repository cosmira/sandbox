<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Cosmira\Sandbox\Support\SandboxTableSynchronizer;
use Illuminate\Database\Eloquent\Builder;

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
     * Indicates if queries should target sandbox data.
     */
    protected static bool $usesSandbox = false;

    private ?string $sandboxActiveTable = null;

    private ?string $sandboxResolvedTable = null;

    /**
     * Get the active table name for the model.
     */
    public function getActiveTable(): string
    {
        return $this->sandboxActiveTable ??= parent::getTable();
    }

    public function setTable($table): static
    {
        $this->getActiveTable();
        $this->sandboxResolvedTable = $table;

        return $this;
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
        return $this->sandboxResolvedTable
            ?? (static::$usesSandbox ? $this->getSandboxTable() : $this->getActiveTable());
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
     * Switch model queries to sandbox data.
     */
    public static function useSandbox(): void
    {
        static::$usesSandbox = true;
    }

    /**
     * Switch model queries to active data.
     */
    public static function useActive(): void
    {
        static::$usesSandbox = false;
    }

    /**
     * Determine if model queries currently target sandbox data.
     */
    public static function isUsingSandbox(): bool
    {
        return static::$usesSandbox;
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
     * Run the callback while model queries target sandbox data.
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
        $query->getModel()->setTable($this->getSandboxTable());

        return $query->from($this->getSandboxTable());
    }

    /**
     * Scope the query to the active table.
     */
    protected function scopeActive(Builder $query): Builder
    {
        $query->getModel()->setTable($this->getActiveTable());

        return $query->from($this->getActiveTable());
    }

    /**
     * Reset sandbox data from the active table.
     */
    public static function resetSandbox(): void
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
     * Apply sandbox data to the active table.
     */
    public static function applySandbox(): void
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
     * Synchronize rows from one configured model table to another.
     */
    private static function syncTables(
        string $sourceTable,
        string $targetTable,
        string $sourceAlias,
        string $targetAlias,
    ): void {
        $instance = new static();

        $instance->getConnection()->transaction(function () use (
            $sourceTable,
            $targetTable,
            $sourceAlias,
            $targetAlias,
            $instance,
        ): void {
            (new SandboxTableSynchronizer($instance->getConnection()))->sync(
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
        $previousState = static::$usesSandbox;
        static::$usesSandbox = $useSandbox;

        try {
            return $callback();
        } finally {
            static::$usesSandbox = $previousState;
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
        return static::$sandboxSyncColumns ?? $this->getConnection()->getSchemaBuilder()->getColumnListing($this->getActiveTable());
    }

    /**
     * Determine if the model supports sandbox synchronization.
     */
    public static function supportsSandboxSync(): bool
    {
        return true;
    }
}
