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

        $registry->useSandboxTables();

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

        $registry->syncIntoSandbox();
        $registry->syncIntoActive();

        $this->assertSame(1, RegistrySandboxModelStub::$syncIntoSandboxCalls);
        $this->assertSame(1, RegistrySandboxModelStub::$syncIntoActiveCalls);
        $this->assertSame(0, OtherRegistrySandboxModelStub::$syncIntoSandboxCalls);
        $this->assertSame(0, OtherRegistrySandboxModelStub::$syncIntoActiveCalls);
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

    public static int $syncIntoSandboxCalls = 0;

    public static int $syncIntoActiveCalls = 0;

    public static function resetState(): void
    {
        static::$usingSandboxTable = false;
        static::$syncIntoSandboxCalls = 0;
        static::$syncIntoActiveCalls = 0;
    }

    public static function isUsingSandboxTable(): bool
    {
        return static::$usingSandboxTable;
    }

    public static function useSandboxTable(): void
    {
        static::$usingSandboxTable = true;
    }

    public static function useActiveTable(): void
    {
        static::$usingSandboxTable = false;
    }

    public static function syncIntoSandbox(): void
    {
        static::$syncIntoSandboxCalls++;
    }

    public static function syncIntoActive(): void
    {
        static::$syncIntoActiveCalls++;
    }
}

class OtherRegistrySandboxModelStub extends RegistrySandboxModelStub
{
    public static bool $usingSandboxTable = false;

    public static int $syncIntoSandboxCalls = 0;

    public static int $syncIntoActiveCalls = 0;
}

class RegistryModelWithoutSwitchingApiStub extends Model
{
    public static function syncIntoSandbox(): void {}

    public static function syncIntoActive(): void {}
}

class RegistryModelWithoutSyncApiStub extends Model
{
    public static function isUsingSandboxTable(): bool
    {
        return false;
    }

    public static function useSandboxTable(): void {}

    public static function useActiveTable(): void {}
}

class RegistryModelWithoutActiveSyncApiStub extends Model
{
    public static function isUsingSandboxTable(): bool
    {
        return false;
    }

    public static function useSandboxTable(): void {}

    public static function useActiveTable(): void {}

    public static function syncIntoSandbox(): void {}
}

class RegistryNonModelSandboxApiStub
{
    public static function isUsingSandboxTable(): bool
    {
        return false;
    }

    public static function useSandboxTable(): void {}

    public static function useActiveTable(): void {}

    public static function syncIntoSandbox(): void {}

    public static function syncIntoActive(): void {}
}
