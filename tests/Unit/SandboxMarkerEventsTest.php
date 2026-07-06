<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Events\SandboxCommitting;
use Cosmira\Sandbox\Events\SandboxResetting;
use Cosmira\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SandboxMarkerEventsTest extends TestCase
{
    #[Test]
    public function markerEventsCanBeCreated(): void
    {
        $this->assertInstanceOf(SandboxCommitting::class, new SandboxCommitting());
        $this->assertInstanceOf(SandboxResetting::class, new SandboxResetting());
    }
}
