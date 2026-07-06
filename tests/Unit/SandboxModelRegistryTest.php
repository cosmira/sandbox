<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;

final class SandboxModelRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RegistrySandboxModelStub::resetState();
        OtherRegistrySandboxModelStub::resetState();
    }

    #[Test]
    public function itRegistersModelsOnceInTheGivenOrder(): void
    {
        $registry = new SandboxModelRegistry();

        $registry->register(
            RegistrySandboxModelStub::class,
            OtherRegistrySandboxModelStub::class,
            RegistrySandboxModelStub::class,
        );

        $this->assertSame([
            RegistrySandboxModelStub::class,
            OtherRegistrySandboxModelStub::class,
        ], $registry->all());
    }

    #[Test]
    public function itSwitchesRegisteredModelsAndRestoresOnlyRememberedModels(): void
    {
        $registry = new SandboxModelRegistry();
        $registry->register(RegistrySandboxModelStub::class);

        $registry->useSandbox();

        $this->assertTrue(RegistrySandboxModelStub::$usingSandboxTable);
        $this->assertFalse(OtherRegistrySandboxModelStub::$usingSandboxTable);

        $registry->restoreActiveTables();

        $this->assertFalse(RegistrySandboxModelStub::$usingSandboxTable);
        $this->assertFalse(OtherRegistrySandboxModelStub::$usingSandboxTable);
    }

    #[Test]
    public function itSyncsRegisteredModelsInBothDirections(): void
    {
        $registry = new SandboxModelRegistry();
        $registry->register(RegistrySandboxModelStub::class);

        $registry->resetSandbox();
        $registry->applySandbox();

        $this->assertSame(1, RegistrySandboxModelStub::$resetSandboxCalls);
        $this->assertSame(1, RegistrySandboxModelStub::$applySandboxCalls);
        $this->assertSame(0, OtherRegistrySandboxModelStub::$resetSandboxCalls);
        $this->assertSame(0, OtherRegistrySandboxModelStub::$applySandboxCalls);
    }

    #[Test]
    public function itRejectsModelsWithoutTheTableSwitchingApi(): void
    {
        $registry = new SandboxModelRegistry();

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        $registry->register(RegistryModelWithoutSwitchingApiStub::class);
    }

    #[Test]
    public function itRejectsModelsWithoutTheSyncApi(): void
    {
        $registry = new SandboxModelRegistry();

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        $registry->register(RegistryModelWithoutSyncApiStub::class);
    }

    #[Test]
    public function itRejectsModelsWithoutTheActiveSyncApi(): void
    {
        $registry = new SandboxModelRegistry();

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        $registry->register(RegistryModelWithoutActiveSyncApiStub::class);
    }

    #[Test]
    public function itRejectsNonModelClassesEvenWhenTheyExposeSandboxMethods(): void
    {
        $registry = new SandboxModelRegistry();

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        $registry->register(RegistryNonModelSandboxApiStub::class);
    }
}

class RegistrySandboxModelStub extends Model
{
    public static bool $usingSandboxTable = false;

    public static int $resetSandboxCalls = 0;

    public static int $applySandboxCalls = 0;

    public static function resetState(): void
    {
        static::$usingSandboxTable = false;
        static::$resetSandboxCalls = 0;
        static::$applySandboxCalls = 0;
    }

    public static function isUsingSandbox(): bool
    {
        return static::$usingSandboxTable;
    }

    public static function useSandbox(): void
    {
        static::$usingSandboxTable = true;
    }

    public static function useActive(): void
    {
        static::$usingSandboxTable = false;
    }

    public static function resetSandbox(): void
    {
        static::$resetSandboxCalls++;
    }

    public static function applySandbox(): void
    {
        static::$applySandboxCalls++;
    }
}

class OtherRegistrySandboxModelStub extends RegistrySandboxModelStub
{
    public static bool $usingSandboxTable = false;

    public static int $resetSandboxCalls = 0;

    public static int $applySandboxCalls = 0;
}

class RegistryModelWithoutSwitchingApiStub extends Model
{
    public static function resetSandbox(): void {}

    public static function applySandbox(): void {}
}

class RegistryModelWithoutSyncApiStub extends Model
{
    public static function isUsingSandbox(): bool
    {
        return false;
    }

    public static function useSandbox(): void {}

    public static function useActive(): void {}
}

class RegistryModelWithoutActiveSyncApiStub extends Model
{
    public static function isUsingSandbox(): bool
    {
        return false;
    }

    public static function useSandbox(): void {}

    public static function useActive(): void {}

    public static function resetSandbox(): void {}
}

class RegistryNonModelSandboxApiStub
{
    public static function isUsingSandbox(): bool
    {
        return false;
    }

    public static function useSandbox(): void {}

    public static function useActive(): void {}

    public static function resetSandbox(): void {}

    public static function applySandbox(): void {}
}
