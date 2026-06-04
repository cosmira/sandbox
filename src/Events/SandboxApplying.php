<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sandbox нужно применить в active-таблицы.
 */
class SandboxApplying
{
    use Dispatchable;
    use SerializesModels;
}
