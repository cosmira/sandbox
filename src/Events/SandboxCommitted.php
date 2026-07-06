<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox draft was committed to active data.
 */
class SandboxCommitted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new sandbox committed event instance.
     */
    public function __construct(
        /**
         * The user that committed the sandbox.
         */
        public readonly int|string $userId,

        /**
         * The time when the sandbox was committed.
         */
        public readonly \DateTimeInterface $committedAt,

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
