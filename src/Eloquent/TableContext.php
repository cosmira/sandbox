<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Eloquent;

use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Illuminate\Database\ConnectionInterface;

/** Model selections owned by one scoped application context. */
final class TableContext
{
    /**
     * Per-model draft selections owned by this application scope.
     *
     * @var array<class-string, bool>
     */
    private array $drafts = [];

    /**
     * Bind the registry used to resolve registered pivot tables.
     *
     * @param SandboxModelRegistry $registry Application model and table registry.
     */
    public function __construct(private readonly SandboxModelRegistry $registry) {}

    /**
     * Resolve a registered active or draft table, returning null for unknown tables.
     */
    public function resolveTable(
        string $table,
        ConnectionInterface $connection,
        bool $draft,
    ): ?string {
        return $this->registry->resolveTable($table, $connection, $draft);
    }

    /**
     * Set the table selection for a model within this application scope.
     *
     * @param class-string $model
     */
    public function select(string $model, bool $draft): void
    {
        $this->drafts[$model] = $draft;
    }

    /**
     * Return whether this scope selects the model draft; active is the default.
     *
     * @param class-string $model
     */
    public function isUsingSandbox(string $model): bool
    {
        return $this->drafts[$model] ?? false;
    }
}
