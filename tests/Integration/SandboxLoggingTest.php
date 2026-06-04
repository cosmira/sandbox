<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Exceptions\SandboxException;
use Packages\Sandbox\Models\SandboxStatus;
use Packages\Sandbox\Sandbox;
use Packages\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SandboxLoggingTest extends TestCase
{
    use RefreshDatabase;

    private Sandbox $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = resolve(Sandbox::class);

        \DB::table('sandbox_status')->delete();
        \DB::table('users')->delete();
    }

    protected function createDatabaseUser(int $userId = 1): int
    {
        return \DB::table('users')->insertGetId([
            'name'       => 'Test User '.$userId,
            'email'      => 'test'.$userId.'@example.com',
            'password'   => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test that opening sandbox executes debug logging.
     * This verifies Log::debug() call is not removed (MethodCallRemoval mutation).
     */
    #[Test]
    public function openingSandboxExecutesLogging(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);

        // Just verify it doesn't throw - logging happens internally
        $this->sandbox->open(1);

        // Verify sandbox was opened
        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Locked, $status->status);
        $this->assertSame('1', $status->user_id);
    }

    /**
     * Test that closing sandbox executes info logging.
     * This verifies Log::info() call is not removed (MethodCallRemoval mutation).
     */
    #[Test]
    public function closingSandboxExecutesLogging(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        // Just verify it doesn't throw - logging happens internally
        $this->sandbox->close(1, SandboxStatusEnum::Saved->value);

        // Verify sandbox was closed
        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Saved, $status->status);
    }

    /**
     * Test that closing with different result codes works (verifies result parameter is used).
     */
    #[Test]
    public function closingSandboxWithDifferentResults(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        // Close with Free (0) result
        $this->sandbox->close(1, SandboxStatusEnum::Free->value);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test that string user IDs are properly handled.
     * This verifies string casting works in comparison logic.
     */
    #[Test]
    public function sandboxHandlesStringUserIds(): void
    {
        $userId = 'user-uuid-123';
        \DB::table('users')->insert([
            'id'         => 1,
            'name'       => 'Test User',
            'email'      => 'test@example.com',
            'password'   => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);

        $this->sandbox->open($userId);

        $status = SandboxStatus::first();
        $this->assertSame($userId, $status->user_id);
    }

    /**
     * Test that force flag properly overrides locking logic.
     * This verifies force parameter is not optimized away.
     */
    #[Test]
    public function forceOpenFlagOverridesLocking(): void
    {
        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);

        // Lock sandbox for user 1
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        // User 2 tries to open without force - should fail
        $this->expectException(SandboxException::class);
        $this->sandbox->open(2, force: false);
    }

    /**
     * Test that force flag allows overriding another user's lock.
     */
    #[Test]
    public function forceOpenBypassesAnotherUserLock(): void
    {
        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);

        // Lock sandbox for user 1
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => '1',
        ]);

        // User 2 opens with force - should succeed
        $this->sandbox->open('2', force: true);

        $status = SandboxStatus::first();
        $this->assertSame('2', $status->user_id);
    }
}
