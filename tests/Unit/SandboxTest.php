<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Events\SandboxApplying;
use Packages\Sandbox\Events\SandboxClosed;
use Packages\Sandbox\Events\SandboxOpened;
use Packages\Sandbox\Events\SandboxResetting;
use Packages\Sandbox\Exceptions\SandboxException;
use Packages\Sandbox\Models\SandboxStatus;
use Packages\Sandbox\Sandbox;
use Packages\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SandboxTest extends TestCase
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

    #[Test]
    public function itCanOpenWhenSandboxIsFree(): void
    {
        Event::fake([SandboxResetting::class, SandboxOpened::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => 1,
        ]);

        $this->sandbox->open(1);

        Event::assertDispatched(SandboxResetting::class);
        Event::assertDispatched(SandboxOpened::class, function (SandboxOpened $event) {
            return $event->userId === 1
                && $event->force === false
                && $event->note === null;
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);
    }

    #[Test]
    public function itThrowsExceptionWhenSandboxIsLockedByAnotherUser(): void
    {
        Event::fake();

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 2,
        ]);

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(20605);

        $this->sandbox->open(1);
    }

    #[Test]
    public function itCanForceOpenWhenLockedByAnotherUser(): void
    {
        Event::fake([SandboxResetting::class, SandboxOpened::class]);

        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 2,
        ]);

        $this->sandbox->open(1, force: true, note: 'Forced open');

        Event::assertDispatched(SandboxResetting::class);
        Event::assertDispatched(SandboxOpened::class, function (SandboxOpened $event) {
            return $event->userId === 1
                && $event->force === true
                && $event->note === 'Forced open';
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);
    }

    #[Test]
    public function itCanRollbackChanges(): void
    {
        Event::fake([SandboxResetting::class, SandboxClosed::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, result: 0);

        Event::assertDispatched(SandboxResetting::class);
        Event::assertDispatched(SandboxClosed::class, function (SandboxClosed $event) {
            return $event->userId === 1
                && $event->result === 0
                && $event->note === null
                && $event->asyncUpdater === false;
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
        $this->assertEquals(0, $status->last_operation);
    }

    #[Test]
    public function itCanCommitChanges(): void
    {
        Event::fake([SandboxApplying::class, SandboxClosed::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, result: 1, asyncUpdater: false);

        Event::assertDispatched(SandboxApplying::class);
        Event::assertDispatched(SandboxClosed::class, function (SandboxClosed $e) {
            return $e->userId === 1
                && $e->result === 1
                && $e->note === null
                && $e->asyncUpdater === false;
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
        $this->assertEquals(1, $status->last_operation);
        $this->assertInstanceOf(Carbon::class, $status->send_date);
    }

    #[Test]
    public function itCanSaveChangesWithoutCommit(): void
    {
        Event::fake([SandboxApplying::class, SandboxClosed::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, result: 2);

        Event::assertNotDispatched(SandboxApplying::class);
        Event::assertDispatched(SandboxClosed::class, function (SandboxClosed $event) {
            return $event->userId === 1
                && $event->result === 2
                && $event->note === null
                && $event->asyncUpdater === false;
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
        $this->assertEquals(2, $status->last_operation);
    }

    #[Test]
    public function itThrowsExceptionWhenClosingFreeSandbox(): void
    {
        SandboxStatus::factory()->create([
            'status' => SandboxStatusEnum::Free,
        ]);

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(20626);

        $this->sandbox->close(1, result: 1);
    }

    #[Test]
    public function itReturnsSandboxStatus(): void
    {
        $this->createDatabaseUser(5);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 5,
        ]);

        $status = $this->sandbox->status();

        $this->assertInstanceOf(SandboxStatus::class, $status);
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(5, $status->user_id);
    }

    #[Test]
    public function itChecksIfUserIsSandboxOwner(): void
    {
        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $status = $this->sandbox->status();
        $this->assertInstanceOf(SandboxStatus::class, $status);
        $this->assertTrue($status->isOwnedBy(1));
        $this->assertFalse($status->isOwnedBy(2));
    }

    #[Test]
    public function itAcceptsUuidAsUserId(): void
    {
        Event::fake([SandboxResetting::class]);

        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => $uuid,
        ]);

        $this->sandbox->open($uuid, note: 'test');

        $status = $this->sandbox->status();
        $this->assertInstanceOf(SandboxStatus::class, $status);
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertSame($uuid, $status->user_id);
        $this->assertTrue($status->isOwnedBy($uuid));
    }
}
