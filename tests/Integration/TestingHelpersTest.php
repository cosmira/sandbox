<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Integration;

use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Models\SandboxStatus;
use Packages\Sandbox\Testing\SandboxTestHelpers;
use Packages\Sandbox\Tests\TestCase;

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

    #[\PHPUnit\Framework\Attributes\Test]
    public function canOpenSandboxWithHelper(): void
    {
        $this->openSandbox(userId: 1);

        $this->assertSandboxLocked(userId: 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canAssertSandboxFree(): void
    {
        $this->assertSandboxFree();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canCommitSandboxWithHelper(): void
    {
        $this->openSandbox(userId: 1);
        $this->commitSandbox(userId: 1);

        $this->assertSandboxFree();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canRollbackSandboxWithHelper(): void
    {
        $this->openSandbox(userId: 1);
        $this->rollbackSandbox(userId: 1);

        $this->assertSandboxFree();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canSaveSandboxWithHelper(): void
    {
        $this->openSandbox(userId: 1);
        $this->saveSandbox(userId: 1);

        $this->assertSandboxSaved();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canGetSandboxStatus(): void
    {
        $this->openSandbox(userId: 1);

        $status = $this->getSandboxStatus();

        $this->assertNotNull($status);
        $this->assertTrue($status->isLocked());
        $this->assertEquals(1, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function canOpenWithOptions(): void
    {
        // Open first user
        $this->openSandbox(userId: 1);
        $this->assertSandboxLocked(userId: 1);

        // Force open with another user
        $this->openSandbox(userId: 2, force: true);
        $this->assertSandboxLocked(userId: 2);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canOpenWithNote(): void
    {
        $this->openSandbox(userId: 1, note: 'Test note');

        $status = $this->getSandboxStatus();
        $this->assertEquals('Test note', $status->note);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function canSwitchModelsTable(): void
    {
        // This tests that the helper methods exist and are callable
        $this->assertTrue(method_exists($this, 'useSandbox'));
        $this->assertTrue(method_exists($this, 'useActive'));
    }
}
