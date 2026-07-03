<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox was opened for editing.
 */
class SandboxOpened
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new sandbox opened event instance.
     */
    public function __construct(
        /**
         * The user that opened the sandbox.
         */
        public readonly int|string $userId,

        /**
         * Indicates if an existing lock was overridden.
         */
        public readonly bool $force,

        /**
         * The optional note attached to the operation.
         */
        public readonly ?string $note,
    ) {}
}
