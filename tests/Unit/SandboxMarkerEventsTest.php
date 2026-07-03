<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Events\SandboxApplying;
use Cosmira\Sandbox\Events\SandboxResetting;
use Cosmira\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SandboxMarkerEventsTest extends TestCase
{
    #[Test]
    public function markerEventsCanBeCreated(): void
    {
        $this->assertInstanceOf(SandboxApplying::class, new SandboxApplying());
        $this->assertInstanceOf(SandboxResetting::class, new SandboxResetting());
    }
}
