<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Illuminate\Http\Request;

/**
 * Resolve the models that should use sandbox tables for a request.
 */
class ResolvingSandboxModels
{
    /**
     * The models switched to sandbox tables through this event.
     *
     * @var array<class-string, true>
     */
    private static array $models = [];

    /**
     * Create a new sandbox model resolving event.
     */
    public function __construct(
        /**
         * The request being handled.
         */
        public readonly Request $request,
    ) {}

    /**
     * Switch the given models to their sandbox tables.
     *
     * @param class-string ...$models
     */
    public function models(string ...$models): void
    {
        foreach ($models as $model) {
            $this->ensureCanSwitchTables($model);

            self::$models[$model] = true;
            $model::useSandboxTable();
        }
    }

    /**
     * Switch all remembered models back to their active tables.
     */
    public static function restoreActiveTables(): void
    {
        foreach (array_keys(self::$models) as $model) {
            if (self::canSwitchTables($model)) {
                $model::useActiveTable();
            }
        }

        self::$models = [];
    }

    /**
     * Ensure the configured class exposes the sandbox table API.
     *
     * @param class-string $model
     *
     * @throws SandboxException
     */
    private function ensureCanSwitchTables(string $model): void
    {
        throw_unless(
            self::canSwitchTables($model),
            SandboxException::class,
            sprintf('Model %s must use HasSandbox trait.', $model),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );
    }

    /**
     * Determine if the configured class exposes the sandbox table API.
     */
    private static function canSwitchTables(string $model): bool
    {
        return class_exists($model)
            && method_exists($model, 'isUsingSandboxTable')
            && method_exists($model, 'useSandboxTable')
            && method_exists($model, 'useActiveTable');
    }
}
