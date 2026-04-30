<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Events\MergeIntoActiveRequested;
use Packages\Sandbox\Events\MergeIntoSandboxRequested;
use Packages\Sandbox\Events\SandboxCommitted;
use Packages\Sandbox\Exceptions\SandboxException;
use Packages\Sandbox\Models\SandboxStatus;
use Packages\Sandbox\Sandbox;
use Packages\Sandbox\Tests\TestCase;

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

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanOpenWhenSandboxIsFree(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => 1,
        ]);

        $this->sandbox->open(1);

        Event::assertDispatched(MergeIntoSandboxRequested::class);
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanForceOpenWhenLockedByAnotherUser(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 2,
        ]);

        $this->sandbox->open(1, force: true);

        Event::assertDispatched(MergeIntoSandboxRequested::class);
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanRollbackChanges(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, result: 0);

        Event::assertDispatched(MergeIntoSandboxRequested::class);
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
        $this->assertEquals(0, $status->last_operation);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCommitChanges(): void
    {
        Event::fake([MergeIntoActiveRequested::class, SandboxCommitted::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, result: 1, asyncUpdater: false);

        Event::assertDispatched(MergeIntoActiveRequested::class);
        Event::assertDispatched(SandboxCommitted::class, function (SandboxCommitted $e) {
            return $e->userId === 1 && $e->asyncUpdater === false;
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
        $this->assertEquals(1, $status->last_operation);
        $this->assertInstanceOf(\Carbon\Carbon::class, $status->send_date);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanSaveChangesWithoutCommit(): void
    {
        Event::fake([MergeIntoActiveRequested::class, SandboxCommitted::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, result: 2);

        Event::assertNotDispatched(MergeIntoActiveRequested::class);
        Event::assertNotDispatched(SandboxCommitted::class);
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
        $this->assertEquals(2, $status->last_operation);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itThrowsExceptionWhenClosingFreeSandbox(): void
    {
        SandboxStatus::factory()->create([
            'status' => SandboxStatusEnum::Free,
        ]);

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(20626);

        $this->sandbox->close(1, result: 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsSandboxStatus(): void
    {
        $this->createDatabaseUser(5);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 5,
        ]);

        $status = $this->sandbox->status();

        $this->assertInstanceOf(\Packages\Sandbox\Models\SandboxStatus::class, $status);
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(5, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itChecksIfUserIsSandboxOwner(): void
    {
        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $status = $this->sandbox->status();
        $this->assertInstanceOf(\Packages\Sandbox\Models\SandboxStatus::class, $status);
        $this->assertTrue($status->isOwnedBy(1));
        $this->assertFalse($status->isOwnedBy(2));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAcceptsUuidAsUserId(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => $uuid,
        ]);

        $this->sandbox->open($uuid, note: 'test');

        $status = $this->sandbox->status();
        $this->assertInstanceOf(\Packages\Sandbox\Models\SandboxStatus::class, $status);
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertSame($uuid, $status->user_id);
        $this->assertTrue($status->isOwnedBy($uuid));
    }
}
