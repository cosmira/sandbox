<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Unit;

use Packages\Sandbox\Contracts\SandboxSyncRunnerInterface;
use Packages\Sandbox\Exceptions\SandboxException;
use Packages\Sandbox\Sandbox;
use Packages\Sandbox\Tests\TestCase;

final class SandboxResetApplyTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function syncToActiveSyncRunnerCalled(): void
    {
        $runner = $this->createMock(SandboxSyncRunnerInterface::class);
        $runner->expects($this->once())
            ->method('syncToSandbox')
            ->with(['my_key' => ['a' => 1]]);
        $this->app->instance(SandboxSyncRunnerInterface::class, $runner);

        $sandbox = resolve(Sandbox::class);
        $sandbox->syncToActive('my_key', ['a' => 1]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function syncToActiveWithEmptyDataDoesNotCallRunner(): void
    {
        $runner = $this->createMock(SandboxSyncRunnerInterface::class);
        $runner->expects($this->never())->method('syncToSandbox');
        $this->app->instance(SandboxSyncRunnerInterface::class, $runner);

        $sandbox = resolve(Sandbox::class);
        $sandbox->syncToActive('key', []);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resetSandboxDataThrowsWhenModelHasNoSyncIntoSandbox(): void
    {
        $sandbox = resolve(Sandbox::class);
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);
        $sandbox->resetSandboxData(\stdClass::class);
    }
}
