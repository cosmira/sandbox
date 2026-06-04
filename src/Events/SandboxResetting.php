<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sandbox нужно сбросить до текущего состояния active-таблиц.
 */
class SandboxResetting
{
    use Dispatchable;
    use SerializesModels;
}
