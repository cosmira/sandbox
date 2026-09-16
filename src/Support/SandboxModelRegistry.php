<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Eloquent\SelectionScope;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\SandboxTable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores sandbox models and applies their draft lifecycle operations.
 */
class SandboxModelRegistry
{
    /**
     * The models that belong to the sandbox workflow.
     *
     * @var list<class-string<Model>>
     */
    private array $models = [];

    /**
     * Registered table definitions indexed by their active table name.
     *
     * @var array<string, SandboxTable>
     */
    private array $tables = [];

    /**
     * Models and table definitions in the dependency order supplied by the host.
     *
     * @var list<class-string<Model>|SandboxTable>
     */
    private array $resources = [];

    /**
     * Shared connection used by registered table definitions.
     */
    private ?ConnectionInterface $tableConnection = null;

    /**
     * Create the stack that restores selections after nested operations.
     */
    public function __construct()
    {
        $this->scope = new SelectionScope();
    }

    /**
     * Register table definitions in order, rejecting overlaps and mixed connections.
     */
    public function registerTables(ConnectionInterface $connection, SandboxTable ...$tables): void
    {
        $this->ensureTableConnection($connection);
        foreach ($tables as $table) {
            foreach ($this->tables as $registered) {
                if ($registered == $table) {
                    continue 2;
                }
                $table->ensureSeparate($registered);
            }
            foreach ($this->models as $model) {
                $this->ensureSeparateTable($model, $table);
            }
            $this->tables[$table->table] = $table;
            $this->resources[] = $table;
            $this->tableConnection = $connection;
        }
    }

    /**
     * Reject a connection different from the registered table connection.
     */
    public function ensureTableConnection(ConnectionInterface $connection): void
    {
        throw_if(
            $this->tableConnection !== null && $this->tableConnection !== $connection,
            SandboxException::class,
            'Registered tables must use the sandbox context connection.',
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );
    }

    /**
     * Return null for a table outside the explicitly registered table set.
     */
    public function resolveTable(
        string $name,
        ConnectionInterface $connection,
        bool $draft,
    ): ?string {
        foreach ($this->tables as $table) {
            if ($name === $table->table || $name === $table->sandboxTable) {
                $this->ensureTableConnection($connection);

                return $draft ? $table->sandboxTable : $table->table;
            }
        }

        return null;
    }

    /**
     * Get the registered sandbox models.
     *
     * @return list<class-string<Model>>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * Selection frames and connection constraints for nested registry operations.
     */
    private readonly SelectionScope $scope;

    /**
     * Ensure the model can be switched between active and sandbox tables.
     *
     * @param class-string $model
     *
     * @throws SandboxException
     */
    private function ensureCanUseSandboxTables(string $model): void
    {
        throw_unless(
            $this->canUseSandboxTables($model),
            SandboxException::class,
            sprintf('Model %s must use HasSandbox trait.', $model),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );
    }

    /**
     * Require models discovered inside an edit to use its transaction connection.
     */
    private function ensureConnection(string $model): void
    {
        if ($this->scope->connection === null) {
            return;
        }

        throw_unless(
            is_subclass_of(
                $model,
                Model::class,
            ) && (new $model())->getConnection() === $this->scope->connection,
            SandboxException::class,
            sprintf('Model %s must use the sandbox context connection.', $model),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );
    }

    /**
     * Ensure the model can synchronize sandbox data.
     *
     * @param class-string $model
     *
     * @throws SandboxException
     */
    private function ensureCanSync(string $model): void
    {
        throw_unless(
            is_subclass_of($model, Model::class) && method_exists($model, 'resetSandbox'),
            SandboxException::class,
            sprintf('Model %s has no resetSandbox(). Use HasSandbox trait.', $model),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );

        throw_unless(
            method_exists($model, 'applySandbox'),
            SandboxException::class,
            sprintf('Model %s has no applySandbox(). Use HasSandbox trait.', $model),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );
    }

    /**
     * Reject model tables that overlap a table-only registration.
     */
    private function ensureSeparateTable(string $model, SandboxTable $table): void
    {
        $instance = new $model();
        if (! method_exists($instance, 'getActiveTable')
            || ! method_exists($instance, 'getSandboxTable')) {
            return;
        }
        $table->ensureSeparateModel($instance->getActiveTable(), $instance->getSandboxTable());
    }

    /**
     * Select tables for the callback and restore all remembered models afterward.
     */
    public function usingTables(
        bool $draft,
        callable $callback,
        ?ConnectionInterface $connection = null,
    ): mixed {
        return $this->scope->run($connection, function () use ($draft, $callback): mixed {
            if ($this->scope->connection !== null) {
                $this->ensureTableConnection($this->scope->connection);
            }
            foreach (array_unique([...$this->all(), ...$this->scope->switched]) as $model) {
                $this->ensureConnection($model);
                $this->scope->remember($model);
                $draft ? $model::useSandbox() : $model::useActive();
            }

            return $callback();
        });
    }

    /**
     * Register models that should participate in the sandbox workflow.
     *
     * @param class-string<Model> ...$models
     */
    public function register(string ...$models): void
    {
        foreach ($models as $model) {
            $this->ensureConnection($model);
            $this->ensureCanUseSandboxTables($model);
            $this->ensureCanSync($model);

            foreach ($this->tables as $table) {
                $this->ensureSeparateTable($model, $table);
            }
            if (! in_array($model, $this->models, true)) {
                $this->resources[] = $model;
            }

            $this->remember($this->models, $model);
        }
    }

    /**
     * Switch registered or given models to sandbox data.
     *
     * @param class-string<Model> ...$models
     */
    public function useSandbox(string ...$models): void
    {
        $models = $models === [] ? $this->all() : $models;

        foreach ($models as $model) {
            $this->ensureCanUseSandboxTables($model);
            $this->ensureConnection($model);

            $this->scope->remember($model);
            $this->remember($this->scope->switched, $model);
            $model::useSandbox();
        }
    }

    /**
     * Restore all switched models to active tables.
     */
    public function restoreActiveTables(): void
    {
        foreach ($this->scope->switched as $model) {
            if ($this->canUseSandboxTables($model)) {
                $model::useActive();
            }
        }

        $this->scope->switched = [];
    }

    /**
     * Reset registered sandbox tables from active tables.
     */
    public function resetSandbox(): void
    {
        foreach ($this->resources as $resource) {
            if ($resource instanceof SandboxTable) {
                $resource->resetSandbox($this->tableConnection);

                continue;
            }
            $resource::resetSandbox();
        }
    }

    /**
     * Apply registered sandbox tables to active tables.
     */
    public function applySandbox(): void
    {
        foreach ($this->resources as $resource) {
            if ($resource instanceof SandboxTable) {
                $resource->applySandbox($this->tableConnection);

                continue;
            }
            $resource::applySandbox();
        }
    }

    /**
     * Determine if the model exposes the sandbox table API.
     *
     * @param class-string $model
     */
    private function canUseSandboxTables(string $model): bool
    {
        return class_exists($model)
            && method_exists($model, 'isUsingSandbox')
            && method_exists($model, 'useSandbox')
            && method_exists($model, 'useActive');
    }

    /**
     * Remember a model once while preserving registration order.
     *
     * @template TModel of Model
     *
     * @param list<class-string<TModel>> $models
     * @param class-string<TModel>       $model
     */
    private function remember(array &$models, string $model): void
    {
        if (! in_array($model, $models, true)) {
            $models[] = $model;
        }
    }

    /**
     * Require every registered resource to use the lifecycle connection.
     */
    public function ensureAllConnections(ConnectionInterface $connection): void
    {
        $this->ensureTableConnection($connection);
        foreach ($this->all() as $modelClass) {
            $this->ensureModelConnection(new $modelClass(), $connection);
        }
    }

    /**
     * Reject a model whose connection differs from the lifecycle connection.
     */
    public function ensureModelConnection(Model $model, ConnectionInterface $connection): void
    {
        throw_unless(
            $model->getConnection() === $connection,
            SandboxException::class,
            sprintf('Model %s must use the sandbox status connection.', $model::class),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );
    }
}
