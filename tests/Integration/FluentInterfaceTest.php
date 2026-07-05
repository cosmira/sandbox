<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Events\SandboxApplying;
use Cosmira\Sandbox\Events\SandboxClosed;
use Cosmira\Sandbox\Events\SandboxResetting;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Facades\Sandbox;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\SandboxBuilder;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class FluentInterfaceTest extends TestCase
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
    public function fluentBuilderCanOpenSandbox(): void
    {
        Event::fake([SandboxResetting::class]);

        Sandbox::for(1)->open();

        $status = SandboxStatus::first();
        $this->assertNotNull($status);
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);

        Event::assertDispatched(SandboxResetting::class);
    }

    #[Test]
    public function fluentBuilderCanCommit(): void
    {
        Event::fake([
            SandboxResetting::class,
            SandboxApplying::class,
            SandboxClosed::class,
        ]);

        Sandbox::for(1)->open();
        Sandbox::for(1)->commit(note: 'Test commit');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);

        Event::assertDispatched(SandboxApplying::class);
        Event::assertDispatched(
            SandboxClosed::class,
            fn (SandboxClosed $event): bool => $event->asyncUpdater === true,
        );
    }

    #[Test]
    public function fluentBuilderCanRollback(): void
    {
        Event::fake([
            SandboxResetting::class,
        ]);

        Sandbox::for(1)->open();
        Sandbox::for(1)->rollback(note: 'Test rollback');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
        $this->assertEquals(SandboxOperation::Rollback, $status->last_operation);

        // Resetting is requested once on open and once on rollback.
        Event::assertDispatchedTimes(SandboxResetting::class, 2);
    }

    #[Test]
    public function fluentBuilderCanSaveWithoutCommit(): void
    {
        Event::fake([SandboxResetting::class]);

        Sandbox::for(1)->open();
        Sandbox::for(1)->save(note: 'Test save');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
        $this->assertEquals(SandboxOperation::Save, $status->last_operation);

        // Saving keeps sandbox data as-is.
        Event::assertDispatchedTimes(SandboxResetting::class, 1);
    }

    #[Test]
    public function fluentBuilderCanGetStatus(): void
    {
        Sandbox::for(1)->open();

        $status = Sandbox::for(1)->status();

        $this->assertNotNull($status);
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);
    }

    #[Test]
    public function fluentBuilderExposesItsUserAndSandboxInstance(): void
    {
        $builder = Sandbox::for(1);

        $this->assertSame(1, $builder->getUserId());
        $this->assertInstanceOf(\Cosmira\Sandbox\Sandbox::class, $builder->getSandbox());
    }

    #[Test]
    public function fluentBuilderCanApplyAndResetModels(): void
    {
        BuilderResetModelStub::$synced = 0;

        $applyBuilder = Sandbox::for(1)->apply(BuilderResetModelStub::class);
        $resetBuilder = Sandbox::for(1)->reset(BuilderResetModelStub::class);

        $this->assertInstanceOf(SandboxBuilder::class, $applyBuilder);
        $this->assertInstanceOf(SandboxBuilder::class, $resetBuilder);
        $this->assertSame(2, BuilderResetModelStub::$synced);
    }

    #[Test]
    public function fluentBuilderCanChainMethods(): void
    {
        Event::fake([
            SandboxResetting::class,
            SandboxApplying::class,
            SandboxClosed::class,
        ]);

        // Test fluent chaining
        $builder = Sandbox::for(1)->open();
        $this->assertInstanceOf(SandboxBuilder::class, $builder);

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
    }

    #[Test]
    public function backwardCompatibilityWithOldAPI(): void
    {
        Event::fake([SandboxResetting::class]);

        // Old API
        app(\Cosmira\Sandbox\Sandbox::class)->open(1);

        $status1 = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status1->status);
        $this->assertEquals(1, $status1->user_id);

        // Store the status for comparison
        $oldStatus = $status1->status;

        // Close for next test
        app(\Cosmira\Sandbox\Sandbox::class)->close(1, SandboxOperation::Rollback);

        // New fluent API
        Sandbox::for(2)->open();

        $status2 = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status2->status);
        $this->assertEquals(2, $status2->user_id);

        // Both APIs should produce the same status
        $this->assertEquals($oldStatus, $status2->status);
    }

    #[Test]
    public function builderThrowsExceptionWhenSandboxLockedByAnotherUser(): void
    {
        Event::fake([SandboxResetting::class]);

        Sandbox::for(1)->open();

        $this->expectException(SandboxException::class);
        Sandbox::for(2)->open();
    }

    #[Test]
    public function builderCanForceOpenWhenLockedByAnotherUser(): void
    {
        Event::fake([SandboxResetting::class]);

        Sandbox::for(1)->open();

        // Force open with user 2
        Sandbox::for(2)->open(force: true);

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(2, $status->user_id);
    }
}

class BuilderResetModelStub extends Model
{
    public static int $synced = 0;

    public static function syncIntoSandbox(): void
    {
        self::$synced++;
    }
}
