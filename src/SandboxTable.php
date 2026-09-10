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
    }

    public function resetSandbox(ConnectionInterface $connection): void
    {
        $this->synchronize($connection, $this->table, $this->sandboxTable);
    }

    public function applySandbox(ConnectionInterface $connection): void
    {
        $this->synchronize($connection, $this->sandboxTable, $this->table);
    }

    private function synchronize(ConnectionInterface $connection, string $source, string $target): void
    {
        (new SandboxTableSynchronizer($connection))->sync(
            sourceTable: $source,
            targetTable: $target,
            keyColumns: $this->primaryKey,
            columns: [],
            changeColumn: null,
        );
    }
}
