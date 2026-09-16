<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Cosmira\Sandbox\Support\SandboxTableSynchronizer;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/** Describes a synchronized table that does not need an Eloquent model. */
final readonly class SandboxTable
{
    /**
     * Physical draft table, defaulting to the active name with the _sb suffix.
     */
    public string $sandboxTable;

    /**
     * Describe an active/draft table pair and validate its keys and tree settings.
     *
     * @param non-empty-list<string> $primaryKey
     * @param string                 $table        Physical active table name.
     * @param ?string                $parentColumn Parent column for tree copy ordering.
     * @param ?SandboxCopyRules      $reset        Draft row filters and update columns.
     */
    public function __construct(
        public string $table,
        public array $primaryKey,
        ?string $sandboxTable = null,
        public ?string $parentColumn = null,
        public ?SandboxCopyRules $reset = null,
    ) {
        $this->sandboxTable = $sandboxTable ?? $table.'_sb';

        $this->validateNames();
        $this->validateKeys($this->primaryKey);
        $this->validateParent();
    }

    /**
     * Require different, nonempty active and draft table names.
     */
    private function validateNames(): void
    {
        $activeIsEmpty = trim($this->table) === '';
        $draftIsEmpty = trim($this->sandboxTable) === '';
        $namesMatch = $this->table === $this->sandboxTable;

        if ($activeIsEmpty || $draftIsEmpty || $namesMatch) {
            throw new InvalidArgumentException(
                'Sandbox tables require distinct, nonempty active and draft names.',
            );
        }
    }

    /**
     * Require a nonempty list of distinct, nonempty string key columns.
     *
     * @param array<array-key, mixed> $keys
     */
    private function validateKeys(array $keys): void
    {
        $keysAreEmpty = $keys === [];
        $keysAreList = array_is_list($keys);

        if ($keysAreEmpty || ! $keysAreList) {
            throw new InvalidArgumentException(
                'Sandbox tables require a nonempty list of primary key columns.',
            );
        }
        foreach ($keys as $column) {
            if (! is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException(
                    'Sandbox primary key columns must be nonempty strings.',
                );
            }
        }
        $uniqueKeys = array_unique($keys);
        if (count($uniqueKeys) !== count($keys)) {
            throw new InvalidArgumentException('Sandbox primary key columns must be unique.');
        }
    }

    /**
     * Require a single primary key and a distinct, nonempty tree parent column.
     */
    private function validateParent(): void
    {
        if ($this->parentColumn === null) {
            return;
        }
        $parentIsEmpty = trim($this->parentColumn) === '';
        $hasCompositeKey = count($this->primaryKey) !== 1;
        $parentIsKey = in_array($this->parentColumn, $this->primaryKey, true);

        if ($parentIsEmpty || $hasCompositeKey || $parentIsKey) {
            throw new InvalidArgumentException(
                'Sandbox trees require one primary key and a distinct parent column.',
            );
        }
    }

    /**
     * Copy active rows into the draft using the configured reset filters.
     */
    public function resetSandbox(ConnectionInterface $connection): void
    {
        $this->synchronize(
            $connection,
            $this->table, $this->sandboxTable,
            $this->reset,
        );
    }

    /**
     * Publish draft rows to the active table.
     */
    public function applySandbox(ConnectionInterface $connection): void
    {
        $this->synchronize($connection, $this->sandboxTable, $this->table);
    }

    /**
     * Copy in the requested direction using this table definition and connection.
     */
    private function synchronize(
        ConnectionInterface $connection,
        string $sourceTable,
        string $targetTable,
        ?SandboxCopyRules $rules = null,
    ): void {
        (new SandboxTableSynchronizer($connection))->sync(
            sourceTable: $sourceTable,
            targetTable: $targetTable,
            keyColumns: $this->primaryKey,
            columns: [],
            changeColumn: null,
            parentColumn: $this->parentColumn,
            rules: $rules,
        );
    }

    /**
     * Reject registrations sharing an active or draft table with another definition.
     */
    public function ensureSeparate(self $registered): void
    {
        $shared = array_intersect(
            [$registered->table, $registered->sandboxTable],
            [$this->table, $this->sandboxTable],
        );
        if ($shared !== []) {
            throw new InvalidArgumentException(
                'Conflicting sandbox table registration: '.$this->table,
            );
        }
    }

    /**
     * Reject a table already represented by a registered model.
     */
    public function ensureSeparateModel(string $active, string $draft): void
    {
        $shared = array_intersect([$active, $draft], [$this->table, $this->sandboxTable]);
        if ($shared !== []) {
            throw new InvalidArgumentException(
                'A sandbox table is already represented by a model: '.$this->table,
            );
        }
    }
}
