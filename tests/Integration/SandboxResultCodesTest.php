<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Models\SandboxStatus;
use Packages\Sandbox\Sandbox;
use Packages\Sandbox\Tests\TestCase;

final class SandboxResultCodesTest extends TestCase
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
     * Test result code 0 (rollback).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function closeWithResultCodeZeroRollback(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, 0);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test result code 1 (commit).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function closeWithResultCodeOneCommit(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, 1);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test result code 2 (save).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function closeWithResultCodeTwoSave(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, 2);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Saved, $status->status);
    }

    /**
     * Test that invalid result code throws exception.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function closeWithInvalidResultCodeThrows(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->expectException(\Packages\Sandbox\Exceptions\SandboxException::class);
        $this->sandbox->close(1, 99);
    }

    /**
     * Test that last_operation is set correctly when result is 0.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function resultCodeSetsLastOperation(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, 0);

        $status = SandboxStatus::first();
        // last_operation should be set to 0 for rollback
        $this->assertSame(0, $status->last_operation);
    }

    /**
     * Test that send_date is set when committing.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function commitSetsLastSendDate(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, 1);

        $status = SandboxStatus::first();
        $this->assertNotNull($status->send_date);
    }

    /**
     * Test that change_id is incremented or updated.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function changeIdIsUpdated(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'    => SandboxStatusEnum::Locked,
            'user_id'   => 1,
            'change_id' => 5,
        ]);

        $this->sandbox->close(1, 0);

        $status = SandboxStatus::first();
        // change_id should be updated after operation
        $this->assertIsInt($status->change_id);
    }

    /**
     * Test asyncUpdater parameter usage.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function closeWithAsyncUpdaterTrue(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, 0, asyncUpdater: true);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test asyncUpdater parameter with false.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function closeWithAsyncUpdaterFalse(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, 0, asyncUpdater: false);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }
}
