<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox draft was saved without being committed.
 */
class SandboxSaved
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new sandbox saved event instance.
     */
    public function __construct(
        /**
         * The user that saved the sandbox.
         */
        public readonly int|string $userId,

        /**
         * The time when the sandbox was saved.
         */
        public readonly \DateTimeInterface $savedAt,

        /**
         * The optional note attached to the operation.
         */
        public readonly ?string $note,
    ) {}
}
