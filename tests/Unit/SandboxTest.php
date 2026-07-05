<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Carbon\Carbon;
use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Events\SandboxApplying;
use Cosmira\Sandbox\Events\SandboxClosed;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\Events\SandboxResetting;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Support\SandboxRecordRestorer;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
    public function itDoesNotResetWhenForceOpeningTheSameOwner(): void
    {
        Event::fake([SandboxResetting::class, SandboxOpened::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->open(1, force: true);

        Event::assertNotDispatched(SandboxResetting::class);
        Event::assertDispatched(SandboxOpened::class);
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

        $this->sandbox->close(1, result: SandboxOperation::Rollback);

        Event::assertDispatched(SandboxResetting::class);
        Event::assertDispatched(SandboxClosed::class, function (SandboxClosed $event) {
            return $event->userId === 1
                && $event->result === SandboxOperation::Rollback
                && $event->note === null
                && $event->asyncUpdater === false;
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
        $this->assertEquals(SandboxOperation::Rollback, $status->last_operation);
    }

    #[Test]
    public function itCanCommitChanges(): void
    {
        Event::fake([SandboxApplying::class, SandboxClosed::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'    => SandboxStatusEnum::Locked,
            'user_id'   => 1,
            'change_id' => 4,
        ]);

        $this->sandbox->close(1, result: SandboxOperation::Commit, asyncUpdater: false);

        Event::assertDispatched(SandboxApplying::class);
        Event::assertDispatched(SandboxClosed::class, function (SandboxClosed $e) {
            return $e->userId === 1
                && $e->result === SandboxOperation::Commit
                && $e->note === null
                && $e->asyncUpdater === false;
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
        $this->assertEquals(SandboxOperation::Commit, $status->last_operation);
        $this->assertSame(5, $status->change_id);
        $this->assertInstanceOf(Carbon::class, $status->send_date);
    }

    #[Test]
    public function itCommitsAsynchronouslyByDefault(): void
    {
        Event::fake([SandboxClosed::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, result: SandboxOperation::Commit);

        Event::assertDispatched(
            SandboxClosed::class,
            fn (SandboxClosed $event): bool => $event->asyncUpdater === true,
        );
    }

    #[Test]
    public function itCanSaveChangesWithoutCommit(): void
    {
        Event::fake([SandboxApplying::class, SandboxClosed::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'    => SandboxStatusEnum::Locked,
            'user_id'   => 1,
            'change_id' => 4,
        ]);

        $this->sandbox->close(1, result: SandboxOperation::Save);

        Event::assertNotDispatched(SandboxApplying::class);
        Event::assertDispatched(SandboxClosed::class, function (SandboxClosed $event) {
            return $event->userId === 1
                && $event->result === SandboxOperation::Save
                && $event->note === null
                && $event->asyncUpdater === false;
        });
        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
        $this->assertEquals(SandboxOperation::Save, $status->last_operation);
        $this->assertSame(5, $status->change_id);
    }

    #[Test]
    public function itThrowsExceptionWhenClosingFreeSandbox(): void
    {
        SandboxStatus::factory()->create([
            'status' => SandboxStatusEnum::Free,
        ]);

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(20626);

        $this->sandbox->close(1, result: SandboxOperation::Commit);
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
    public function itUsesTheInjectedModelRegistry(): void
    {
        $models = new TrackingSandboxRegistry();
        $sandbox = new Sandbox(models: $models);

        $sandbox->models(InjectedSandboxModelStub::class);

        $this->assertSame([[InjectedSandboxModelStub::class]], $models->registeredModels);
    }

    #[Test]
    public function itUsesTheInjectedRecordRestorerForSingleModelResets(): void
    {
        $recordRestorer = new TrackingSandboxRecordRestorer();
        $sandbox = new Sandbox(recordRestorer: $recordRestorer);
        $model = new InjectedSandboxModelStub();

        $sandbox->resetSandboxData($model);

        $this->assertSame([$model], $recordRestorer->restoredModels);
    }

    #[Test]
    public function itRejectsNonModelClassesDuringReset(): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        $this->sandbox->resetSandboxData(NonModelWithSandboxSyncStub::class);
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
    public function itComparesOwnerIdsByStringValue(): void
    {
        $status = new SandboxStatus([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->assertTrue($status->isOwnedBy('1'));
    }

    #[Test]
    public function itOpensForSameOwnerWhenStoredIdTypeDiffers(): void
    {
        Event::fake([SandboxResetting::class, SandboxOpened::class]);

        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->open('1');

        Event::assertNotDispatched(SandboxResetting::class);
        Event::assertDispatched(SandboxOpened::class);
    }

    #[Test]
    public function factoryCreatesTheSingletonStatusRow(): void
    {
        $status = SandboxStatus::factory()->make();

        $this->assertSame(1, $status->id);
        $this->assertSame(0, $status->change_id);
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

class TrackingSandboxRegistry extends SandboxModelRegistry
{
    /**
     * The model batches registered through the injected registry.
     *
     * @var array<int, array<int, class-string<Model>>>
     */
    public array $registeredModels = [];

    public function register(string ...$models): void
    {
        $this->registeredModels[] = $models;
    }
}

class TrackingSandboxRecordRestorer extends SandboxRecordRestorer
{
    /**
     * The models restored through the injected restorer.
     *
     * @var array<int, Model>
     */
    public array $restoredModels = [];

    public function restore(Model $model): void
    {
        $this->restoredModels[] = $model;
    }
}

class InjectedSandboxModelStub extends Model
{
    public static function syncIntoSandbox(): void {}
}

class NonModelWithSandboxSyncStub
{
    public static function syncIntoSandbox(): void {}
}
