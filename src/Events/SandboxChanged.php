<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A model operation is complete and ready for synchronous preparation before commit.
 */
final readonly class SandboxChanged
{
    /**
     * Bind completion to its model scope and transaction connection.
     *
     * @param class-string<Model> $model The model whose operation completed.
     */
    public function __construct(
        /**
         * The model whose operation completed.
         */
        public string $model,
        /**
         * The connection holding the operation transaction.
         */
        public ConnectionInterface $connection,
    ) {}
}
