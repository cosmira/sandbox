<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores sandbox models and applies their table lifecycle operations.
 */
class SandboxModelRegistry
{
    /**
     * The models that belong to the sandbox workflow.
     *
     * @var array<int, class-string<Model>>
     */
    private array $models = [];

    /**
     * The models switched to sandbox tables for the current execution context.
     *
     * @var array<int, class-string<Model>>
     */
    private array $switched = [];

    /**
     * Register models that should participate in the sandbox workflow.
     *
     * @param class-string<Model> ...$models
     */
    public function register(string ...$models): void
    {
        foreach ($models as $model) {
            $this->ensureCanUseSandboxTables($model);
            $this->ensureCanSync($model);

            $this->remember($this->models, $model);
        }
    }

    /**
     * Get the registered sandbox models.
     *
     * @return array<int, class-string<Model>>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * Switch registered or given models to sandbox tables.
     *
     * @param class-string<Model> ...$models
     */
    public function useSandboxTables(string ...$models): void
    {
        $models = $models === [] ? $this->all() : $models;

        foreach ($models as $model) {
            $this->ensureCanUseSandboxTables($model);

            $this->remember($this->switched, $model);
            $model::useSandboxTable();
        }
    }

    /**
     * Restore all switched models to active tables.
     */
    public function restoreActiveTables(): void
    {
        foreach ($this->switched as $model) {
            if ($this->canUseSandboxTables($model)) {
                $model::useActiveTable();
            }
        }

        $this->switched = [];
    }

    /**
     * Sync registered active tables into their sandbox tables.
     */
    public function syncIntoSandbox(): void
    {
        foreach ($this->all() as $model) {
            $model::syncIntoSandbox();
        }
    }

    /**
     * Sync registered sandbox tables into their active tables.
     */
    public function syncIntoActive(): void
    {
        foreach ($this->all() as $model) {
            $model::syncIntoActive();
        }
    }

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
     * Ensure the model can synchronize sandbox data.
     *
     * @param class-string $model
     *
     * @throws SandboxException
     */
    private function ensureCanSync(string $model): void
    {
        throw_unless(
            is_subclass_of($model, Model::class) && method_exists($model, 'syncIntoSandbox'),
            SandboxException::class,
            sprintf('Model %s has no syncIntoSandbox(). Use HasSandbox trait.', $model),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );

        throw_unless(
            method_exists($model, 'syncIntoActive'),
            SandboxException::class,
            sprintf('Model %s has no syncIntoActive(). Use HasSandbox trait.', $model),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );
    }

    /**
     * Determine if the model exposes the sandbox table API.
     *
     * @param class-string $model
     */
    private function canUseSandboxTables(string $model): bool
    {
        return class_exists($model)
            && method_exists($model, 'isUsingSandboxTable')
            && method_exists($model, 'useSandboxTable')
            && method_exists($model, 'useActiveTable');
    }

    /**
     * Remember a model once while preserving registration order.
     *
     * @param array<int, class-string<Model>> $models
     * @param class-string<Model>             $model
     */
    private function remember(array &$models, string $model): void
    {
        if (! in_array($model, $models, true)) {
            $models[] = $model;
        }
    }
}
