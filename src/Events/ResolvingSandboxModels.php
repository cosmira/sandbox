<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Illuminate\Http\Request;

/**
 * Resolve the models that should use sandbox tables for a request.
 */
class ResolvingSandboxModels
{
    /**
     * Create a new sandbox model resolving event.
     */
    public function __construct(
        /**
         * The request being handled.
         */
        public readonly Request $request,

        /**
         * The registry that switches models for the current request.
         */
        private readonly ?SandboxModelRegistry $registry = null,
    ) {}

    /**
     * Switch the given models to their sandbox tables.
     *
     * @param class-string ...$models
     */
    public function models(string ...$models): void
    {
        $this->registry()->useSandboxTables(...$models);
    }

    /**
     * Switch all remembered models back to their active tables.
     */
    public static function restoreActiveTables(): void
    {
        app(SandboxModelRegistry::class)->restoreActiveTables();
    }

    /**
     * Get the registry used by this event.
     */
    private function registry(): SandboxModelRegistry
    {
        return $this->registry ?? app(SandboxModelRegistry::class);
    }
}
