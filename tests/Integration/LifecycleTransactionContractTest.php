<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Events\SandboxCommitted;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\Events\SandboxRolledBack;
use Cosmira\Sandbox\Events\SandboxSaved;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class LifecycleTransactionContractTest extends TestCase
{
    public static function completionOperations(): array
    {
        return [
            ['commit', SandboxCommitted::class],
            ['rollback', SandboxRolledBack::class],
            ['save', SandboxSaved::class],
        ];
    }

    #[Test]
    public function openedEventIsDiscardedWhenOuterTransactionRollsBack(): void
    {
        Event::fake([SandboxOpened::class]);
        $sandbox = app(Sandbox::class);
        $connection = $sandbox->connection();
        $connection->beginTransaction();

        try {
            $sandbox->open(1);
            $this->assertTrue($sandbox->status()->isLockedBy(1));
            Event::assertNotDispatched(SandboxOpened::class);
        } finally {
            $connection->rollBack();
        }
        $this->assertTrue($sandbox->status()->isFree());
        Event::assertNotDispatched(SandboxOpened::class);
    }

    #[Test]
    #[DataProvider('completionOperations')]
    public function completionEventWaitsForOuterCommit(string $operation, string $event): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->open(1);
        Event::fake([$event]);
        $connection = $sandbox->connection();
        $connection->beginTransaction();

        try {
            $sandbox->{$operation}(1);
            Event::assertNotDispatched($event);
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        Event::assertDispatchedTimes($event, 1);
    }

    #[Test]
    #[DataProvider('completionOperations')]
    public function completionEventIsDiscardedAfterRollback(string $operation, string $event): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->open(1);
        $before = SandboxStatus::firstOrFail()->getAttributes();
        Event::fake([$event]);
        $connection = $sandbox->connection();
        $connection->beginTransaction();

        try {
            $sandbox->{$operation}(1);
            Event::assertNotDispatched($event);
        } finally {
            $connection->rollBack();
        }
        $this->assertSame($before, SandboxStatus::firstOrFail()->getAttributes());
        Event::assertNotDispatched($event);
    }
}
