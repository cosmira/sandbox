<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Eloquent;

use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Illuminate\Database\ConnectionInterface;

/** Model selections owned by one scoped application context. */
final class TableContext
{
    /** @var array<class-string, bool> */
    private array $drafts = [];

    public function __construct(private readonly SandboxModelRegistry $registry) {}

    public function resolveTable(
        string $table,
        ConnectionInterface $connection,
        bool $draft,
    ): ?string {
        return $this->registry->resolveTable($table, $connection, $draft);
    }

    /** @param class-string $model */
    public function select(string $model, bool $draft): void
    {
        $this->drafts[$model] = $draft;
    }

    /** @param class-string $model */
    public function isUsingSandbox(string $model): bool
    {
        return $this->drafts[$model] ?? false;
    }
}
