<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Integration;

use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Models\SandboxStatus;
use Packages\Sandbox\Tests\TestCase;

/**
 * @group commands
 */
final class CommandsTest extends TestCase
{
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
    public function canOpenSandboxViaCommand(): void
    {
        $this->artisan('sandbox:open', ['userId' => '1'])
            ->assertSuccessful()
            ->expectsOutput('Sandbox opened for user: 1');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canCloseSandboxWithCommitViaCommand(): void
    {
        // First open
        app(\Packages\Sandbox\Sandbox::class)->open(1);

        // Then close with commit
        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => '1',
        ])
            ->assertSuccessful()
            ->expectsOutput('Sandbox closed with result: commit');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canCloseSandboxWithRollbackViaCommand(): void
    {
        app(\Packages\Sandbox\Sandbox::class)->open(1);

        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => '0',
        ])
            ->assertSuccessful()
            ->expectsOutput('Sandbox closed with result: rollback');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canCloseSandboxWithSaveViaCommand(): void
    {
        app(\Packages\Sandbox\Sandbox::class)->open(1);

        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => '2',
        ])
            ->assertSuccessful()
            ->expectsOutput('Sandbox closed with result: save');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canCheckStatusViaCommand(): void
    {
        // Check free status
        $this->artisan('sandbox:status')
            ->assertSuccessful()
            ->expectsOutput('Sandbox is FREE (not in use)');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canCheckLockedStatusViaCommand(): void
    {
        app(\Packages\Sandbox\Sandbox::class)->open(1);

        $this->artisan('sandbox:status')
            ->assertSuccessful()
            ->expectsOutput('Sandbox is LOCKED by user: 1');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function canCheckStatusVerbose(): void
    {
        app(\Packages\Sandbox\Sandbox::class)->open(1);

        $this->artisan('sandbox:status', ['--details' => true])
            ->assertSuccessful()
            ->expectsOutput('Sandbox is LOCKED by user: 1')
            ->expectsOutput('Detailed Information:');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function commandRejectsInvalidResult(): void
    {
        app(\Packages\Sandbox\Sandbox::class)->open(1);

        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => '99',
        ])
            ->assertFailed()
            ->expectsOutput('Result code must be 0 (rollback), 1 (commit), or 2 (save)');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function openCommandCanForce(): void
    {
        // Open for user 1
        app(\Packages\Sandbox\Sandbox::class)->open(1);

        // Try to force open for user 2
        $this->artisan('sandbox:open', [
            'userId'  => '2',
            '--force' => true,
        ])
            ->assertSuccessful();

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(2, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function openCommandWithNote(): void
    {
        $this->artisan('sandbox:open', [
            'userId' => '1',
            '--note' => 'Testing sandbox',
        ])
            ->assertSuccessful();

        $status = SandboxStatus::first();
        $this->assertEquals('Testing sandbox', $status->note);
    }
}
