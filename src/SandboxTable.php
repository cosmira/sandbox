<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Cosmira\Sandbox\Support\SandboxTableSynchronizer;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/** Describes a synchronized table that does not need an Eloquent model. */
final readonly class SandboxTable
{
    public string $sandboxTable;

    /** @param non-empty-list<string> $primaryKey */
    public function __construct(
        public string $table,
        public array $primaryKey,
        ?string $sandboxTable = null,
        public ?string $parentColumn = null,
        public ?SandboxCopyRules $reset = null,
    ) {
        $this->sandboxTable = $sandboxTable ?? $table.'_sb';

        if (trim($table) === '' || trim($this->sandboxTable) === '' || $table === $this->sandboxTable) {
            throw new InvalidArgumentException('Sandbox tables require distinct, nonempty active and draft names.');
        }
        if ($primaryKey === [] || ! array_is_list($primaryKey)) {
            throw new InvalidArgumentException('Sandbox tables require a nonempty list of primary key columns.');
        }
        foreach ($primaryKey as $column) {
            if (! is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException('Sandbox primary key columns must be nonempty strings.');
            }
        }
        if (count(array_unique($primaryKey)) !== count($primaryKey)) {
            throw new InvalidArgumentException('Sandbox primary key columns must be unique.');
        }
        if ($parentColumn !== null && (trim($parentColumn) === '' || count($primaryKey) !== 1
            || $parentColumn === $primaryKey[0])) {
            throw new InvalidArgumentException('Sandbox trees require one primary key and a distinct parent column.');
        }
    }

    public function resetSandbox(ConnectionInterface $connection): void
    {
        $this->synchronize($connection, $this->table, $this->sandboxTable, $this->reset);
    }

    public function applySandbox(ConnectionInterface $connection): void
    {
        $this->synchronize($connection, $this->sandboxTable, $this->table);
    }

    private function synchronize(
        ConnectionInterface $connection,
        string $source,
        string $target,
        ?SandboxCopyRules $rules = null,
    ): void {
        (new SandboxTableSynchronizer($connection))->sync(
            sourceTable: $source,
            targetTable: $target,
            keyColumns: $this->primaryKey,
            columns: [],
            changeColumn: null,
            parentColumn: $this->parentColumn,
            rules: $rules,
        );
    }
}
