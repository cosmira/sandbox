<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Facades\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AutoUserDetectionTest extends TestCase
{
    #[Test]
    public function canUseMeMethodWithAuthenticatedUser(): void
    {
        $this->actingAs($user = $this->createUser(id: 42));

        Sandbox::me()->open()->commit();

        $this->assertSandboxFree();
    }

    #[Test]
    public function meMacroThrowsWithoutAuthenticatedUser(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated user found');

        Sandbox::me();
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function openCommandWithExplicitUserIgnoresAuth(): void
    {
        $this->actingAs($this->createUser(id: 1));

        $this->artisan('sandbox:open', ['userId' => '456'])
            ->assertSuccessful()
            ->expectsOutput('Sandbox opened for user: 456');

        $status = Sandbox::status();
        $this->assertEquals(456, $status->user_id);
    }

    #[Test]
    public function openCommandFailsWithoutAuthAndNoUserId(): void
    {
        $this->artisan('sandbox:open')
            ->assertFailed()
            ->expectsOutput('No user specified and no authenticated user found');
    }

    #[Test]
    public function rollbackCommandAutodetectsCurrentUser(): void
    {
        $this->actingAs($user = $this->createUser(id: 789));

        Sandbox::for(789)->open();

        $this->artisan('sandbox:rollback')
            ->assertSuccessful()
            ->expectsOutput('Sandbox rolled back');

        $this->assertSandboxFree();
    }

    #[Test]
    public function commitCommandWithExplicitUserIgnoresAuth(): void
    {
        $this->actingAs($this->createUser(id: 1));

        Sandbox::for(222)->open();

        $this->artisan('sandbox:commit', ['userId' => '222'])
            ->assertSuccessful();

        $this->assertSandboxFree();
    }

    #[Test]
    public function testHelpersWorkWithoutUserIdParameter(): void
    {
        $this->actingAs($user = $this->createUser(id: 555));

        // All helpers should work without explicit userId
        $this->openSandbox();
        $this->assertSandboxLocked();
        $this->commitSandbox();
        $this->assertSandboxFree();
    }

    #[Test]
    public function testHelpersThrowWithoutAuthAndNoUserId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No user ID provided and no authenticated user found');

        $this->openSandbox();
    }

    #[Test]
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

    #[Test]
    public function meMethodPreservesFluentApiChaining(): void
    {
        $this->actingAs($user = $this->createUser(id: 888));

        Sandbox::me()
            ->open(force: true, note: 'Auto-detected user')
            ->commit(note: 'All fluent');

        $this->assertSandboxFree();
    }
}
