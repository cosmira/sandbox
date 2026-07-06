<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class SandboxOperationsTest extends TestCase
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
     * Test rollback result.
     */
    #[Test]
    public function closeWithRollbackResult(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->rollback(1);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test commit result.
     */
    #[Test]
    public function closeWithCommitResult(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->commit(1);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test save result.
     */
    #[Test]
    public function closeWithSaveResult(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->save(1);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Saved, $status->status);
    }

    /**
     * Test that invalid operation values are rejected by the enum boundary.
     */
    #[Test]
    public function invalidResultValueThrows(): void
    {
        $this->assertNull(SandboxOperation::tryFrom(99));
    }

    #[Test]
    public function operationsCanBeResolvedFromCliInput(): void
    {
        $this->assertSame(SandboxOperation::Rollback, SandboxOperation::tryFromInput('rollback'));
        $this->assertSame(SandboxOperation::Commit, SandboxOperation::tryFromInput('commit'));
        $this->assertSame(SandboxOperation::Commit, SandboxOperation::tryFromInput(' COMMIT '));
        $this->assertSame(SandboxOperation::Save, SandboxOperation::tryFromInput('save'));
        $this->assertSame(SandboxOperation::Commit, SandboxOperation::tryFromInput('1'));
        $this->assertSame(SandboxOperation::Save, SandboxOperation::tryFromInput(2));
        $this->assertNull(SandboxOperation::tryFromInput('publish'));
    }

    #[Test]
    public function operationsExposeLabelsAndDescriptions(): void
    {
        $this->assertSame('rollback', SandboxOperation::Rollback->label());
        $this->assertSame('commit', SandboxOperation::Commit->label());
        $this->assertSame('save', SandboxOperation::Save->label());
        $this->assertSame('Rollback', SandboxOperation::Rollback->description());
        $this->assertSame('Commit', SandboxOperation::Commit->description());
        $this->assertSame('Save without commit', SandboxOperation::Save->description());
    }

    #[Test]
    public function statusesExposeLabelsAndDescriptions(): void
    {
        $this->assertSame('Free', SandboxStatusEnum::Free->label());
        $this->assertSame('Locked', SandboxStatusEnum::Locked->label());
        $this->assertSame('Saved', SandboxStatusEnum::Saved->label());
        $this->assertSame(
            'Sandbox is free (not in use)',
            SandboxStatusEnum::Free->description(),
        );
        $this->assertSame(
            'Sandbox is locked (user is editing)',
            SandboxStatusEnum::Locked->description(),
        );
        $this->assertSame(
            'Sandbox is saved (not locked, data persisted)',
            SandboxStatusEnum::Saved->description(),
        );
    }

    #[Test]
    public function statusCanBeConvertedToLegacyArray(): void
    {
        $status = SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 'user-1',
        ]);

        $this->assertSame([
            'status'  => SandboxStatusEnum::Locked->value,
            'user_id' => 'user-1',
        ], $status->toStatusArray());
    }

    /**
     * Test that last_operation is set correctly when rolling back.
     */
    #[Test]
    public function rollbackSetsLastOperation(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->rollback(1);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxOperation::Rollback, $status->last_operation);
    }

    /**
     * Test that send_date is set when committing.
     */
    #[Test]
    public function commitSetsLastSendDate(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->commit(1);

        $status = SandboxStatus::first();
        $this->assertNotNull($status->send_date);
    }

    #[Test]
    public function changeIdIsIncrementedWhenSandboxCloses(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'    => SandboxStatusEnum::Locked,
            'user_id'   => 1,
            'change_id' => 5,
        ]);

        $this->sandbox->rollback(1);

        $status = SandboxStatus::first();
        $this->assertSame(6, $status->change_id);
    }

    /**
     * Test asyncUpdater parameter usage.
     */
    #[Test]
    public function commitWithAsyncUpdaterTrue(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->commit(1, asyncUpdater: true);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test asyncUpdater parameter with false.
     */
    #[Test]
    public function commitWithAsyncUpdaterFalse(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->commit(1, asyncUpdater: false);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }
}
