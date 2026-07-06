<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The sandbox draft is about to be committed to active data.
 */
class SandboxCommitting
{
    use Dispatchable;
    use SerializesModels;
}
