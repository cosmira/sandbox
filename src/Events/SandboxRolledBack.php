<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox draft was rolled back and released.
 */
class SandboxRolledBack
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new sandbox rolled back event instance.
     */
    public function __construct(
        /**
         * The user that rolled back the sandbox.
         */
        public readonly int|string $userId,

        /**
         * The time when the sandbox was rolled back.
         */
        public readonly \DateTimeInterface $rolledBackAt,

        /**
         * The optional note attached to the operation.
         */
        public readonly ?string $note,
    ) {}
}
