<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

        Log::shouldReceive('debug')
            ->once()
            ->with('Opening sandbox', ['user_id' => 1]);
        Log::shouldReceive('info')
            ->once()
            ->with('Sandbox opened', ['user_id' => 1]);

        $this->sandbox->open(1);

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

        Log::shouldReceive('debug')
            ->once()
            ->with('Closing sandbox', [
                'user_id' => 1,
                'result'  => 'save',
            ]);
        Log::shouldReceive('info')
            ->once()
            ->with('Sandbox closed', [
                'user_id' => 1,
                'result'  => 'save',
            ]);

        $this->sandbox->close(1, SandboxOperation::Save);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Saved, $status->status);
    }

    /**
     * Test that closing with a different result works (verifies result parameter is used).
     */
    #[Test]
    public function closingSandboxWithDifferentResults(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, SandboxOperation::Rollback);

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
