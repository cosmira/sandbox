<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Integration;

use Packages\Sandbox\Facades\Sandbox;
use Packages\Sandbox\Tests\TestCase;

class AutoUserDetectionTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function canUseMeMethodWithAuthenticatedUser(): void
    {
        $this->actingAs($user = $this->createUser(id: 42));

        Sandbox::me()->open()->commit();

        $this->assertSandboxFree();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function meMacroThrowsWithoutAuthenticatedUser(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated user found');

        Sandbox::me();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fluentApiWorksWithMeMethod(): void
    {
        $this->actingAs($user = $this->createUser(id: 99));

        Sandbox::me()->open(note: 'Testing auto-user');
        $status = Sandbox::status();

        $this->assertTrue($status->isLocked());
        $this->assertEquals(99, $status->user_id);

        Sandbox::me()->commit();
        $this->assertSandboxFree();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function openCommandAutodetectsCurrentUser(): void
    {
        $this->actingAs($user = $this->createUser(id: 123));

        $this->artisan('sandbox:open')
            ->assertSuccessful()
            ->expectsOutput('Sandbox opened for user: 123');

        $status = Sandbox::status();
        $this->assertTrue($status->isLocked());
        $this->assertEquals(123, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function openCommandWithExplicitUserIgnoresAuth(): void
    {
        $this->actingAs($this->createUser(id: 1));

        $this->artisan('sandbox:open', ['userId' => '456'])
            ->assertSuccessful()
            ->expectsOutput('Sandbox opened for user: 456');

        $status = Sandbox::status();
        $this->assertEquals(456, $status->user_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function openCommandFailsWithoutAuthAndNoUserId(): void
    {
        $this->artisan('sandbox:open')
            ->assertFailed()
            ->expectsOutput('No user specified and no authenticated user found');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function closeCommandAutodetectsCurrentUser(): void
    {
        $this->actingAs($user = $this->createUser(id: 789));

        Sandbox::for(789)->open();

        $this->artisan('sandbox:close')
            ->assertSuccessful()
            ->expectsOutput('Sandbox closed with result: rollback');

        $this->assertSandboxFree();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function closeCommandWithExplicitUserIgnoresAuth(): void
    {
        $this->actingAs($this->createUser(id: 1));

        Sandbox::for(222)->open();

        $this->artisan('sandbox:close', ['userId' => '222', '--result' => 1])
            ->assertSuccessful();

        $this->assertSandboxFree();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testHelpersWorkWithoutUserIdParameter(): void
    {
        $this->actingAs($user = $this->createUser(id: 555));

        // All helpers should work without explicit userId
        $this->openSandbox();
        $this->assertSandboxLocked();
        $this->commitSandbox();
        $this->assertSandboxFree();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testHelpersThrowWithoutAuthAndNoUserId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No user ID provided and no authenticated user found');

        $this->openSandbox();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function allTestHelpersHaveSingleUserVariant(): void
    {
        $this->actingAs($user = $this->createUser(id: 777));

        // openSandbox
        $this->openSandbox();
        $this->commitSandbox();

        // rollbackSandbox
        $this->openSandbox();
        $this->rollbackSandbox();

        // saveSandbox
        $this->openSandbox();
        $this->saveSandbox();

        // assertSandboxLocked
        $this->openSandbox();
        $this->assertSandboxLocked();
        $this->commitSandbox();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function meMethodPreservesFluentApiChaining(): void
    {
        $this->actingAs($user = $this->createUser(id: 888));

        Sandbox::me()
            ->open(force: true, note: 'Auto-detected user')
            ->commit(note: 'All fluent');

        $this->assertSandboxFree();
    }
}
