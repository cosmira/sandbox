<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Integration;

use Illuminate\Support\Facades\Event;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Events\MergeIntoActiveRequested;
use Packages\Sandbox\Events\MergeIntoSandboxRequested;
use Packages\Sandbox\Events\SandboxCommitted;
use Packages\Sandbox\Exceptions\SandboxException;
use Packages\Sandbox\Facades\Sandbox;
use Packages\Sandbox\Models\SandboxStatus;
use Packages\Sandbox\Tests\TestCase;

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

    #[\PHPUnit\Framework\Attributes\Test]
    public function fluentBuilderCanOpenSandbox(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        Sandbox::for(1)->open();

        $status = SandboxStatus::first();
        $this->assertNotNull($status);
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);

        Event::assertDispatched(MergeIntoSandboxRequested::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fluentBuilderCanCommit(): void
    {
        Event::fake([
            MergeIntoSandboxRequested::class,
            MergeIntoActiveRequested::class,
            SandboxCommitted::class,
        ]);

        Sandbox::for(1)->open();
        Sandbox::for(1)->commit(note: 'Test commit');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);

        Event::assertDispatched(MergeIntoActiveRequested::class);
        Event::assertDispatched(SandboxCommitted::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fluentBuilderCanRollback(): void
    {
        Event::fake([
            MergeIntoSandboxRequested::class,
        ]);

        Sandbox::for(1)->open();
        Sandbox::for(1)->rollback(note: 'Test rollback');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Free, $status->status);
        $this->assertEquals(0, $status->last_operation);

        // MergeIntoSandboxRequested should be dispatched twice - once on open, once on rollback
        Event::assertDispatchedTimes(MergeIntoSandboxRequested::class, 2);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fluentBuilderCanSaveWithoutCommit(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        Sandbox::for(1)->open();
        Sandbox::for(1)->save(note: 'Test save');

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Saved, $status->status);
        $this->assertEquals(2, $status->last_operation);

        // MergeIntoSandboxRequested should only be dispatched on open, not on save
        Event::assertDispatchedTimes(MergeIntoSandboxRequested::class, 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fluentBuilderCanGetStatus(): void
    {
        Sandbox::for(1)->open();

        $status = Sandbox::for(1)->status();

        $this->assertNotNull($status);
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(1, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fluentBuilderCanChainMethods(): void
    {
        Event::fake([
            MergeIntoSandboxRequested::class,
            MergeIntoActiveRequested::class,
            SandboxCommitted::class,
        ]);

        // Test fluent chaining
        $builder = Sandbox::for(1)->open();
        $this->assertInstanceOf(\Packages\Sandbox\SandboxBuilder::class, $builder);

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function backwardCompatibilityWithOldAPI(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        // Old API
        app(\Packages\Sandbox\Sandbox::class)->open(1);

        $status1 = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status1->status);
        $this->assertEquals(1, $status1->user_id);

        // Store the status for comparison
        $oldStatus = $status1->status;
        $oldUserId = $status1->user_id;

        // Close for next test
        app(\Packages\Sandbox\Sandbox::class)->close(1, 0); // rollback

        // New fluent API
        Sandbox::for(2)->open();

        $status2 = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status2->status);
        $this->assertEquals(2, $status2->user_id);

        // Both APIs should produce the same status
        $this->assertEquals($oldStatus, $status2->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function builderThrowsExceptionWhenSandboxLockedByAnotherUser(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        Sandbox::for(1)->open();

        $this->expectException(SandboxException::class);
        Sandbox::for(2)->open();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function builderCanForceOpenWhenLockedByAnotherUser(): void
    {
        Event::fake([MergeIntoSandboxRequested::class]);

        Sandbox::for(1)->open();

        // Force open with user 2
        Sandbox::for(2)->open(force: true);

        $status = SandboxStatus::first();
        $this->assertEquals(SandboxStatusEnum::Locked, $status->status);
        $this->assertEquals(2, $status->user_id);
    }
}
