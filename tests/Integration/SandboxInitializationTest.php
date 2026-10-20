<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Backends\EloquentSandboxBackend;
use Cosmira\Sandbox\Events\SandboxInitialized;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class SandboxInitializationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['initialization_items', 'initialization_items_sb'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('name');
            });
        }

        DB::table('initialization_items')->insert(['id' => 1, 'name' => 'Active']);
        DB::table('initialization_items_sb')->insert(['id' => 2, 'name' => 'Previous draft']);

        app(SandboxModelRegistry::class)->registerTables(
            DB::connection(),
            new SandboxTable('initialization_items', ['id']),
        );
    }

    protected function tearDown(): void
    {
        Schema::connection('testing')->dropIfExists('initialization_items_sb');
        Schema::connection('testing')->dropIfExists('initialization_items');

        parent::tearDown();
    }

    #[Test]
    public function preparationSeesCopiedDataAndFinishesBeforeEditing(): void
    {
        $sandbox = app(Sandbox::class);
        Event::fake([SandboxOpened::class]);
        Event::listen(SandboxInitialized::class, function (SandboxInitialized $event): void {
            $this->assertSame(['Active'], $event->connection->table('initialization_items_sb')->pluck('name')->all());
            $event->connection->table('initialization_items_sb')->update(['name' => 'Prepared']);
        });

        $sandbox->edit(1, function () use ($sandbox): void {
            Event::assertNotDispatched(SandboxOpened::class);
            $this->assertTrue($sandbox->status()->isLockedBy(1));
            $this->assertSame('Prepared', DB::table('initialization_items_sb')->value('name'));
        });

        Event::assertDispatchedTimes(SandboxOpened::class, 1);
        $this->assertSame('Active', DB::table('initialization_items')->value('name'));
    }

    #[Test]
    public function failedPreparationRestoresPreviousDraftAndPreventsEditing(): void
    {
        $sandbox = app(Sandbox::class);
        Event::fake([SandboxOpened::class]);
        Event::listen(SandboxInitialized::class, function (SandboxInitialized $event): void {
            $event->connection->table('initialization_items_sb')->update(['name' => 'Partial preparation']);

            throw new RuntimeException('Projection preparation failed');
        });

        try {
            $sandbox->edit(1, fn () => $this->fail('Editing started before preparation succeeded.'));
            $this->fail('Preparation failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Projection preparation failed', $exception->getMessage());
        }

        $this->assertTrue($sandbox->status()->isFree());
        $this->assertSame(['Previous draft'], DB::table('initialization_items_sb')->pluck('name')->all());
        Event::assertNotDispatched(SandboxOpened::class);
    }

    #[Test]
    public function reopeningAndResumingPreserveDraftPreparationAndEdits(): void
    {
        $sandbox = app(Sandbox::class);
        Event::listen(SandboxInitialized::class, function (SandboxInitialized $event): void {
            $event->connection->table('initialization_items_sb')->update(['name' => 'Prepared']);
        });
        $sandbox->edit(1, fn () => DB::table('initialization_items_sb')->update(['name' => 'Edited']));

        $sandbox->open(1);
        $this->assertSame('Edited', DB::table('initialization_items_sb')->value('name'));

        $sandbox->save(1);
        $sandbox->open(2);
        $this->assertSame('Edited', DB::table('initialization_items_sb')->value('name'));

        $sandbox->open(3, force: true);
        $this->assertSame('Edited', DB::table('initialization_items_sb')->value('name'));
    }

    #[Test]
    public function customInitializationStillRunsRegisteredPreparation(): void
    {
        $backend = new class(app(SandboxModelRegistry::class)) extends EloquentSandboxBackend
        {
            protected function initializeDraft(): void
            {
                $this->connection()->table('initialization_items_sb')->update(['name' => 'Custom draft']);
            }
        };
        Event::listen(SandboxInitialized::class, function (SandboxInitialized $event): void {
            $this->assertSame('Custom draft', $event->connection->table('initialization_items_sb')->value('name'));
            $event->connection->table('initialization_items_sb')->update(['name' => 'Prepared custom draft']);
        });

        $backend->open(1);

        $this->assertSame('Prepared custom draft', DB::table('initialization_items_sb')->value('name'));
    }

    #[Test]
    public function preparationUsesLifecycleConnectionWhenDefaultConnectionDiffers(): void
    {
        $sandbox = new Sandbox(backend: new EloquentSandboxBackend(
            app(SandboxModelRegistry::class),
            statusModel: (new SandboxStatus())->setConnection('testing'),
        ));
        $connection = $sandbox->connection();
        Event::listen(SandboxInitialized::class, function (SandboxInitialized $event) use ($connection): void {
            $this->assertSame($connection, $event->connection);
            $event->connection->table('initialization_items_sb')->update(['name' => 'Prepared']);

            throw new RuntimeException('Preparation failed on lifecycle connection');
        });
        config(['database.connections.unrelated' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::setDefaultConnection('unrelated');

        try {
            $sandbox->edit(1, fn () => $this->fail('Editing continued after failure.'));
            $this->fail('Preparation failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Preparation failed on lifecycle connection', $exception->getMessage());
        } finally {
            DB::setDefaultConnection('testing');
        }

        $this->assertTrue($sandbox->status()->isFree());
        $this->assertSame(['Previous draft'], $connection->table('initialization_items_sb')->pluck('name')->all());
        $this->assertSame(0, $connection->transactionLevel());
    }
}
