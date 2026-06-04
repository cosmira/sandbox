<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox was opened for editing.
 */
class SandboxOpened
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int|string $userId,
        public readonly bool $force,
        public readonly ?string $note,
    ) {}
}
