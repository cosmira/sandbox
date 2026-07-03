<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox was closed with a result.
 */
class SandboxClosed
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new sandbox closed event instance.
     */
    public function __construct(
        /**
         * The user that closed the sandbox.
         */
        public readonly int|string $userId,

        /**
         * The operation used to close the sandbox.
         */
        public readonly SandboxOperation $result,

        /**
         * The time when the sandbox was closed.
         */
        public readonly \DateTimeInterface $closedAt,

        /**
         * The optional note attached to the operation.
         */
        public readonly ?string $note,

        /**
         * Indicates if the updater should run asynchronously.
         */
        public readonly bool $asyncUpdater,
    ) {}
}
