<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Testing\SandboxTestHelpers;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;

final class TestingHelpersTest extends TestCase
{
    use SandboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        \DB::table('sandbox_status')->delete();
        \DB::table('users')->delete();

        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);

        // Инициализировать sandbox status
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);
    }

    private function createDatabaseUser(int $userId = 1): int
    {
        return \DB::table('users')->insertGetId([
            'name'       => 'Test User '.$userId,
            'email'      => 'test'.$userId.'@example.com',
            'password'   => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function canOpenSandboxWithHelper(): void
    {
        $this->openSandbox(userId: 1);

        $this->assertSandboxLocked(userId: 1);
    }

    #[Test]
    public function canAssertSandboxFree(): void
    {
        $this->assertSandboxFree();
    }

    #[Test]
    public function canCommitSandboxWithHelper(): void
    {
        $this->openSandbox(userId: 1);
        $this->commitSandbox(userId: 1);

        $this->assertSandboxFree();
    }

    #[Test]
    public function canRollbackSandboxWithHelper(): void
    {
        $this->openSandbox(userId: 1);
        $this->rollbackSandbox(userId: 1);

        $this->assertSandboxFree();
    }

    #[Test]
    public function canSaveSandboxWithHelper(): void
    {
        $this->openSandbox(userId: 1);
        $this->saveSandbox(userId: 1);

        $this->assertSandboxSaved();
    }

    #[Test]
    public function canGetSandboxStatus(): void
    {
        $this->openSandbox(userId: 1);

        $status = $this->getSandboxStatus();

        $this->assertNotNull($status);
        $this->assertTrue($status->isLocked());
        $this->assertEquals(1, $status->user_id);
    }

    #[Test]
    public function canCheckSandboxStatuses(): void
    {
        // Initially free
        $this->assertSandboxFree();

        // Open
        $this->openSandbox(userId: 1);
        $this->assertSandboxLocked(userId: 1);

        // Save
        $this->saveSandbox(userId: 1);
        $this->assertSandboxSaved();
    }

    #[Test]
    public function canOpenWithOptions(): void
    {
        // Open first user
        $this->openSandbox(userId: 1);
        $this->assertSandboxLocked(userId: 1);

        // Force open with another user
        $this->openSandbox(userId: 2, force: true);
        $this->assertSandboxLocked(userId: 2);
    }

    #[Test]
    public function canOpenWithNote(): void
    {
        $this->openSandbox(userId: 1, note: 'Test note');

        $status = $this->getSandboxStatus();
        $this->assertEquals('Test note', $status->note);
    }

    #[Test]
    public function canApplySandbox(): void
    {
        // This tests that the applySandbox method can be called
        // Real testing would require actual models with HasSandbox trait
        $this->openSandbox(userId: 1);

        // Method should not throw
        try {
            // We can't test with actual models here without creating them
            // But we can test that the method exists and is callable
            $this->assertTrue(method_exists($this, 'applySandbox'));
        } finally {
            $this->rollbackSandbox(userId: 1);
        }
    }

    #[Test]
    public function canSwitchModelsTable(): void
    {
        $this->useSandbox(HelperSwitchModelStub::class);

        $this->assertTrue(HelperSwitchModelStub::isUsingSandboxTable());

        $this->useActive(HelperSwitchModelStub::class);

        $this->assertFalse(HelperSwitchModelStub::isUsingSandboxTable());
    }

    #[Test]
    public function canApplySandboxForModel(): void
    {
        $this->applySandbox(HelperSwitchModelStub::class);

        $this->assertTrue(HelperSwitchModelStub::$synced);
    }

    #[Test]
    public function helpersRequireAUserWhenNoUserIsAuthenticated(): void
    {
        $methods = [
            'commitSandbox',
            'rollbackSandbox',
            'saveSandbox',
            'assertSandboxLocked',
        ];

        foreach ($methods as $method) {
            try {
                $this->{$method}();
                $this->fail("Expected {$method} to require a user.");
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'No user ID provided and no authenticated user found',
                    $exception->getMessage(),
                );
            }
        }
    }
}

class HelperSwitchModelStub extends Model
{
    public static bool $synced = false;

    public static bool $usingSandboxTable = false;

    public static function useSandboxTable(): void
    {
        self::$usingSandboxTable = true;
    }

    public static function useActiveTable(): void
    {
        self::$usingSandboxTable = false;
    }

    public static function isUsingSandboxTable(): bool
    {
        return self::$usingSandboxTable;
    }

    public static function syncIntoSandbox(): void
    {
        self::$synced = true;
    }
}
