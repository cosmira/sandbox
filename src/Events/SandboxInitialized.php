<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Database\ConnectionInterface;

/**
 * Fresh draft data is ready for synchronous preparation inside the opening transaction.
 */
final readonly class SandboxInitialized
{
    /**
     * Bind preparation to the connection that copied the draft.
     */
    public function __construct(
        /**
         * The connection holding the opening transaction.
         */
        public ConnectionInterface $connection,
    ) {}
}
