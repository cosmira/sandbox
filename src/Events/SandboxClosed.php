<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox was closed with a result.
 */
class SandboxClosed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int|string $userId,
        public readonly int $result,
        public readonly \DateTimeInterface $closedAt,
        public readonly ?string $note,
        public readonly bool $asyncUpdater,
    ) {}
}
