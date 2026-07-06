<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores sandbox models and applies their draft lifecycle operations.
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
     * The models switched to sandbox data for the current execution context.
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
     * Switch registered or given models to sandbox data.
     *
     * @param class-string<Model> ...$models
     */
    public function useSandbox(string ...$models): void
    {
        $models = $models === [] ? $this->all() : $models;

        foreach ($models as $model) {
            $this->ensureCanUseSandboxTables($model);

            $this->remember($this->switched, $model);
            $model::useSandbox();
        }
    }

    /**
     * Restore all switched models to active tables.
     */
    public function restoreActiveTables(): void
    {
        foreach ($this->switched as $model) {
            if ($this->canUseSandboxTables($model)) {
                $model::useActive();
            }
        }

        $this->switched = [];
    }

    /**
     * Reset registered sandbox tables from active tables.
     */
    public function resetSandbox(): void
    {
        foreach ($this->all() as $model) {
            $model::resetSandbox();
        }
    }

    /**
     * Apply registered sandbox tables to active tables.
     */
    public function applySandbox(): void
    {
        foreach ($this->all() as $model) {
            $model::applySandbox();
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
