<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SandboxResetTest extends TestCase
{
    #[Test]
    public function resetThrowsWhenModelCannotResetSandbox(): void
    {
        $sandbox = resolve(Sandbox::class);
        $sandbox->open(1);
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);
        $sandbox->reset(1, \stdClass::class);
    }
}
