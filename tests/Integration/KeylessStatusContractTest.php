<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Backends\EloquentSandboxBackend;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class KeylessStatusContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('keyless_status', function (Blueprint $table): void {
            $table->integer('status');
            $table->integer('user_id');
            $table->integer('last_operation')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('change_date')->nullable();
        });
        config(['sandbox.table' => 'keyless_status', 'sandbox.status_primary_key' => null]);
        DB::table('keyless_status')->insert(['status' => 0, 'user_id' => 1]);
        foreach (['keyless_items', 'keyless_items_sb'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('name');
            });
        }
        DB::table('keyless_items')->insert(['id' => 1, 'name' => 'Active']);
        DB::table('keyless_items_sb')->insert(['id' => 1, 'name' => 'Old draft']);
        app(Sandbox::class)->tables(new SandboxTable('keyless_items', ['id']));
        Event::fake([SandboxOpened::class]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('keyless_items_sb');
        Schema::dropIfExists('keyless_items');
        Schema::dropIfExists('keyless_status');
        parent::tearDown();
    }

    #[Test]
    public function firstEditCopiesActiveAndRepeatedOwnerContinuesWithoutReset(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->edit(7, function (): void {
            $this->assertSame('Active', DB::table('keyless_items_sb')->value('name'));
            DB::table('keyless_items_sb')->update(['name' => 'Edited']);
        });
        $before = $sandbox->status()->getAttributes();
        $sandbox->open(7, note: 'Ignored on continuation');
        $this->assertSame($before, $sandbox->status()->getAttributes());
        $this->assertSame('Edited', DB::table('keyless_items_sb')->value('name'));
        $this->assertSame('Active', DB::table('keyless_items')->value('name'));
        $this->assertTrue($sandbox->status()->fresh()->isLockedBy(7));
        $this->assertTrue($sandbox->status()->refresh()->isLockedBy(7));
        Event::assertDispatchedTimes(SandboxOpened::class, 1);
    }

    #[Test]
    public function savedDraftIsPreservedAndOtherOwnerCannotEditLockedDraft(): void
    {
        DB::table('keyless_status')->update(['status' => 2]);
        $sandbox = app(Sandbox::class);
        $sandbox->open(7);
        $this->assertSame('Old draft', DB::table('keyless_items_sb')->value('name'));
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_SANDBOX_LOCKED);
        $sandbox->edit(8, fn () => $this->fail('Other owner edited the draft.'));
    }

    public static function invalidCardinalities(): iterable
    {
        yield 'missing' => [0];
        yield 'duplicate' => [2];
    }

    #[Test]
    #[DataProvider('invalidCardinalities')]
    public function invalidSingletonFailsBeforeCopyingOrEditing(int $rows): void
    {
        DB::table('keyless_status')->delete();
        for ($index = 0; $index < $rows; $index++) {
            DB::table('keyless_status')->insert(['status' => 0, 'user_id' => 1]);
        }

        try {
            app(Sandbox::class)->edit(7, fn () => $this->fail('Invalid singleton allowed editing.'));
            $this->fail('Invalid singleton accepted.');
        } catch (SandboxException) {
            $this->assertSame('Old draft', DB::table('keyless_items_sb')->value('name'));
            Event::assertNothingDispatched();
        }
    }

    public static function rejectedUpdates(): iterable
    {
        yield 'saving veto' => ['saving', 'veto'];
        yield 'updating veto' => ['updating', 'veto'];
        yield 'observer removes singleton' => ['updated', 'delete'];
        yield 'observer duplicates singleton' => ['updated', 'duplicate'];
    }

    #[Test]
    #[DataProvider('rejectedUpdates')]
    public function rejectedPersistenceRollsBackOpening(string $event, string $action): void
    {
        $eventName = 'eloquent.'.$event.': '.SandboxStatus::class;
        Event::listen($eventName, function () use ($action): ?bool {
            if ($action === 'veto') {
                return false;
            }
            if ($action === 'delete') {
                DB::table('keyless_status')->delete();
            } else {
                DB::table('keyless_status')->insert(['status' => 0, 'user_id' => 1]);
            }

            return null;
        });

        try {
            app(Sandbox::class)->edit(7, fn () => $this->fail('Rejected persistence allowed editing.'));
            $this->fail('Rejected persistence accepted.');
        } catch (SandboxException) {
            $this->assertSame('Old draft', DB::table('keyless_items_sb')->value('name'));
            $this->assertSame(1, DB::table('keyless_status')->count());
            $this->assertSame(0, DB::table('keyless_status')->value('status'));
            Event::assertNothingDispatched();
        } finally {
            Event::forget($eventName);
        }
    }

    #[Test]
    public function enclosingRollbackRestoresStatusAndDraftWithoutOpenedEvent(): void
    {
        DB::beginTransaction();
        app(Sandbox::class)->open(7);
        $this->assertTrue(app(Sandbox::class)->status()->isLockedBy(7));
        DB::rollBack();
        $this->assertTrue(app(Sandbox::class)->status()->isFree());
        $this->assertSame('Old draft', DB::table('keyless_items_sb')->value('name'));
        Event::assertNothingDispatched();
    }

    public static function corruptReads(): iterable
    {
        foreach (['status', 'fresh', 'refresh'] as $operation) {
            foreach ([0, 2] as $rows) {
                yield $operation.' with '.$rows.' rows' => [$operation, $rows];
            }
        }
    }

    #[Test]
    #[DataProvider('corruptReads')]
    public function readsRejectAnInvalidSingleton(string $operation, int $rows): void
    {
        $status = app(Sandbox::class)->status();
        DB::table('keyless_status')->delete();
        for ($index = 0; $index < $rows; $index++) {
            DB::table('keyless_status')->insert(['status' => 0, 'user_id' => 1]);
        }

        $this->expectException(SandboxException::class);
        if ($operation === 'status') {
            app(Sandbox::class)->status();
        } else {
            $status->{$operation}();
        }
    }

    #[Test]
    public function keylessUpdatesRequireAnEnclosingTransaction(): void
    {
        $status = app(Sandbox::class)->status();
        $this->expectException(\LogicException::class);
        $status->forceFill(['note' => 'Unprotected update'])->save();
    }

    #[Test]
    public function injectedStatusModelUsesItsOwnConnection(): void
    {
        config(['database.connections.secondary' => [
            ...config('database.connections.testing'),
            'prefix' => 'secondary_',
        ]]);
        $connection = DB::connection('secondary');
        $this->beforeApplicationDestroyed(fn () => $connection->getSchemaBuilder()->dropIfExists('keyless_status'));
        $connection->getSchemaBuilder()->create('keyless_status', function (Blueprint $table): void {
            $table->integer('status');
            $table->integer('user_id');
            $table->integer('last_operation')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('change_date')->nullable();
        });
        $connection->table('keyless_status')->insert(['status' => 0, 'user_id' => 1]);
        $backend = new EloquentSandboxBackend(
            new SandboxModelRegistry(),
            statusModel: (new SandboxStatus())->setConnection('secondary'),
        );
        $backend->open(7);
        $this->assertTrue($backend->status()->refresh()->isLockedBy(7));
        $this->assertTrue(app(Sandbox::class)->status()->isFree());
    }
}
