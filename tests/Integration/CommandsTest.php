<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Carbon\Carbon;
use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Events\SandboxCommitted;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Support\Facades\Event;
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
    public function canCommitSandboxViaCommand(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:commit', ['userId' => '1'])
            ->assertSuccessful()
            ->expectsOutput('Sandbox committed');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
    }

    #[Test]
    public function commitCommandPassesAsyncFlagToTheSandboxLifecycle(): void
    {
        app(Sandbox::class)->open(1);
        Event::fake([SandboxCommitted::class]);

        $this->artisan('sandbox:commit', [
            'userId'  => '1',
            '--async' => true,
        ])->assertSuccessful();

        Event::assertDispatched(
            SandboxCommitted::class,
            fn (SandboxCommitted $event): bool => $event->asyncUpdater === true,
        );
    }

    #[Test]
    public function commitCommandAutodetectsCurrentUser(): void
    {
        $this->actingAs($this->createUser(id: 4));

        app(Sandbox::class)->open(4);

        $this->artisan('sandbox:commit')
            ->assertSuccessful()
            ->expectsOutput('Sandbox committed');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
    }

    #[Test]
    public function canRollbackSandboxViaCommand(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:rollback', ['userId' => '1'])
            ->assertSuccessful()
            ->expectsOutput('Sandbox rolled back');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
    }

    #[Test]
    public function canSaveSandboxViaCommand(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:save', ['userId' => '1'])
            ->assertSuccessful()
            ->expectsOutput('Sandbox saved');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
    }

    #[Test]
    public function saveCommandAutodetectsCurrentUser(): void
    {
        $this->actingAs($this->createUser(id: 3));

        app(Sandbox::class)->open(3);

        $this->artisan('sandbox:save')
            ->assertSuccessful()
            ->expectsOutput('Sandbox saved');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
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
        app(Sandbox::class)->save(1);

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
    public function commitCommandFailsWithoutAuthAndNoUserId(): void
    {
        $this->artisan('sandbox:commit')
            ->assertFailed()
            ->expectsOutput('No user specified and no authenticated user found');
    }

    #[Test]
    public function rollbackCommandFailsWithoutAuthAndNoUserId(): void
    {
        $this->artisan('sandbox:rollback')
            ->assertFailed()
            ->expectsOutput('No user specified and no authenticated user found');
    }

    #[Test]
    public function saveCommandFailsWithoutAuthAndNoUserId(): void
    {
        $this->artisan('sandbox:save')
            ->assertFailed()
            ->expectsOutput('No user specified and no authenticated user found');
    }

    #[Test]
    public function commitCommandReportsSandboxErrors(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:commit', ['userId' => '2'])
            ->assertFailed()
            ->expectsOutput('Failed to commit sandbox: Sandbox is locked by other user 1');
    }

    #[Test]
    public function rollbackCommandReportsSandboxErrors(): void
    {
        $this->artisan('sandbox:rollback', ['userId' => '1'])
            ->assertFailed()
            ->expectsOutput('Failed to roll back sandbox: Cannot close: sandbox is already free. Use open() first.');
    }

    #[Test]
    public function saveCommandReportsSandboxErrors(): void
    {
        app(Sandbox::class)->open(1);

        $this->artisan('sandbox:save', ['userId' => '2'])
            ->assertFailed()
            ->expectsOutput('Failed to save sandbox: Sandbox is locked by other user 1');
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
