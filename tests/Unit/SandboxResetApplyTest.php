<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Unit;

use Packages\Sandbox\Exceptions\SandboxException;
use Packages\Sandbox\Sandbox;
use Packages\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SandboxResetApplyTest extends TestCase
{
    #[Test]
    public function resetSandboxDataThrowsWhenModelHasNoSyncIntoSandbox(): void
    {
        $sandbox = resolve(Sandbox::class);
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);
        $sandbox->resetSandboxData(\stdClass::class);
    }
}
