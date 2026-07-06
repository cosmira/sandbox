<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox draft is about to be rolled back from active data.
 */
class SandboxRollingBack
{
    use Dispatchable;
    use SerializesModels;
}
