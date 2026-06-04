<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Exceptions\SandboxException;
use Packages\Sandbox\Models\SandboxStatus;
use Packages\Sandbox\Sandbox;
use Packages\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SandboxExceptionHandlingTest extends TestCase
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
     * Test that closing free sandbox throws SandboxException.
     * This verifies the throw_if() call for free state check.
     */
    #[Test]
    public function closingFreeSandboxThrowsException(): void
    {
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);

        $this->expectException(SandboxException::class);
        $this->sandbox->close(1, 0);
    }

    /**
     * Test that other user cannot close sandbox locked by someone else.
     * Verifies user ID comparison and locking logic (with non-zero result).
     */
    #[Test]
    public function otherUserCannotCloseLockedSandbox(): void
    {
        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);

        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        // result=1 (commit) triggers the user lock check
        $this->expectException(SandboxException::class);
        $this->sandbox->close(2, 1);
    }

    /**
     * Test that result=0 does not trigger user lock exception.
     * Verifies the && $result !== 0 condition.
     */
    #[Test]
    public function closingWithZeroResultIgnoresUserLock(): void
    {
        $this->createDatabaseUser(1);
        $this->createDatabaseUser(2);

        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        // Should NOT throw because result is 0
        $this->sandbox->close(2, 0);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test that string and int user IDs are compared correctly (casting).
     */
    #[Test]
    public function stringAndIntUserIdsCompareCorrectly(): void
    {
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => '1',
        ]);

        // Should NOT throw - int 1 casts to string '1'
        $this->sandbox->close(1, 0);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Free, $status->status);
    }

    /**
     * Test that sandbox status not found throws RuntimeException.
     * This verifies throw_unless() call.
     */
    #[Test]
    public function openWithoutStatusThrows(): void
    {
        $this->createDatabaseUser(1);
        // Don't create sandbox status

        $this->expectException(\RuntimeException::class);
        $this->sandbox->open(1);
    }

    /**
     * Test that opening free sandbox succeeds.
     */
    #[Test]
    public function openingFreeSandboxSucceeds(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);

        $this->sandbox->open(1);

        $status = SandboxStatus::first();
        $this->assertSame(SandboxStatusEnum::Locked, $status->status);
        $this->assertSame('1', $status->user_id);
    }

    /**
     * Test that note parameter is recorded when sandbox is opened.
     */
    #[Test]
    public function openingWithNoteRecordsIt(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);

        $this->sandbox->open(1, note: 'Testing note');

        $status = SandboxStatus::first();
        $this->assertSame('Testing note', $status->note);
    }

    /**
     * Test that note parameter is recorded when sandbox is closed.
     */
    #[Test]
    public function closingWithNoteRecordsIt(): void
    {
        $this->createDatabaseUser(1);
        SandboxStatus::factory()->create([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->sandbox->close(1, SandboxStatusEnum::Saved->value, note: 'Closing note');

        $status = SandboxStatus::first();
        $this->assertSame('Closing note', $status->note);
    }
}
