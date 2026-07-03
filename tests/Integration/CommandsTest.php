<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Carbon\Carbon;
use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function canOpenSandboxViaCommand(): void
    {
        $this->artisan('sandbox:open', ['userId' => '1'])
            ->assertSuccessful()
            ->expectsOutput('Sandbox opened for user: 1');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);
    }

    #[Test]
    public function canCloseSandboxWithCommitViaCommand(): void
    {
        // First open
        app(Sandbox::class)->open(1);

        // Then close with commit
        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => 'commit',
        ])
            ->assertSuccessful()
            ->expectsOutput('Sandbox closed with result: commit');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
    }

    #[Test]
    public function canCloseSandboxWithRollbackViaCommand(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => 'rollback',
        ])
            ->assertSuccessful()
            ->expectsOutput('Sandbox closed with result: rollback');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
    }

    #[Test]
    public function canCloseSandboxWithSaveViaCommand(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => 'save',
        ])
            ->assertSuccessful()
            ->expectsOutput('Sandbox closed with result: save');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
    }

    #[Test]
    public function closeCommandAcceptsLegacyNumericResult(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => '1',
        ])
            ->assertSuccessful()
            ->expectsOutput('Sandbox closed with result: commit');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
    }

    #[Test]
    public function canCheckStatusViaCommand(): void
    {
        // Check free status
        $this->artisan('sandbox:status')
            ->assertSuccessful()
            ->expectsOutput('Sandbox is FREE (not in use)');
    }

    #[Test]
    public function canCheckLockedStatusViaCommand(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:status')
            ->assertSuccessful()
            ->expectsOutput('Sandbox is LOCKED by user: 1');
    }

    #[Test]
    public function canCheckSavedStatusViaCommand(): void
    {
        app(Sandbox::class)->open(1);
        app(Sandbox::class)->close(1, SandboxOperation::Save);

        $this->artisan('sandbox:status')
            ->assertSuccessful()
            ->expectsOutput('Sandbox is SAVED (user: 1)');
    }

    #[Test]
    public function statusCommandFailsWhenStatusRowIsMissing(): void
    {
        \DB::table('sandbox_status')->delete();

        $this->artisan('sandbox:status')
            ->assertFailed()
            ->expectsOutput('Sandbox status not found in database');
    }

    #[Test]
    public function canCheckStatusVerbose(): void
    {
        $changedAt = Carbon::parse('2026-01-02 03:04:05');
        $sentAt = Carbon::parse('2026-01-03 04:05:06');

        SandboxStatus::query()->update([
            'status'         => SandboxStatusEnum::Locked,
            'user_id'        => 1,
            'last_operation' => SandboxOperation::Commit,
            'change_date'    => $changedAt,
            'send_date'      => $sentAt,
            'note'           => 'Ready to inspect',
        ]);

        $this->artisan('sandbox:status', ['--details' => true])
            ->assertSuccessful()
            ->expectsOutput('Sandbox is LOCKED by user: 1')
            ->expectsOutput('Detailed Information:')
            ->expectsTable(['Key', 'Value'], [
                ['Status Code', 'Locked'],
                ['User ID', 1],
                ['Last Operation', 'Commit'],
                ['Changed At', '2026-01-02 03:04:05'],
                ['Sent At', '2026-01-03 04:05:06'],
                ['Note', 'Ready to inspect'],
            ]);
    }

    #[Test]
    public function statusDetailsUseFallbacksForEmptyValues(): void
    {
        $changedAt = Carbon::parse('2026-01-02 03:04:05');

        SandboxStatus::query()->update([
            'status'         => SandboxStatusEnum::Free,
            'user_id'        => null,
            'last_operation' => null,
            'change_date'    => $changedAt,
            'send_date'      => null,
            'note'           => null,
        ]);

        $this->artisan('sandbox:status', ['--details' => true])
            ->assertSuccessful()
            ->expectsTable(['Key', 'Value'], [
                ['Status Code', 'Free'],
                ['User ID', 'N/A'],
                ['Last Operation', 'N/A'],
                ['Changed At', '2026-01-02 03:04:05'],
                ['Sent At', 'N/A'],
                ['Note', 'N/A'],
            ]);
    }

    #[Test]
    public function commandRejectsInvalidResult(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:close', [
            'userId'   => '1',
            '--result' => '99',
        ])
            ->assertFailed()
            ->expectsOutput('Result must be rollback, commit, or save');
    }

    #[Test]
    public function closeCommandFailsWithoutAuthAndNoUserId(): void
    {
        $this->artisan('sandbox:close')
            ->assertFailed()
            ->expectsOutput('No user specified and no authenticated user found');
    }

    #[Test]
    public function closeCommandReportsSandboxErrors(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:close', [
            'userId'   => '2',
            '--result' => 'commit',
        ])
            ->assertFailed()
            ->expectsOutput('Failed to close sandbox: Sandbox is locked by other user 1');
    }

    #[Test]
    public function openCommandCanForce(): void
    {
        // Open for user 1
        app(Sandbox::class)->open(1);

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

    #[Test]
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

    #[Test]
    public function openCommandReportsSandboxErrors(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:open', [
            'userId' => '2',
        ])
            ->assertFailed()
            ->expectsOutput('Failed to open sandbox: Sandbox is locked by other user 1');
    }
}
