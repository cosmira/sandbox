<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox should be reset from the active tables.
 */
class SandboxResetting
{
    use Dispatchable;
    use SerializesModels;
}
