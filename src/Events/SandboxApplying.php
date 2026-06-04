<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox should be applied to the active tables.
 */
class SandboxApplying
{
    use Dispatchable;
    use SerializesModels;
}
