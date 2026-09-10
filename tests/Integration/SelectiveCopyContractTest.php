<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Backends\EloquentSandboxBackend;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\SandboxCopyRules;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class SelectiveCopyContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['copy_items', 'copy_items_sb'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('source');
                $table->string('name');
                $table->string('external_value');
            });
        }
    }

    #[Test]
    public function hostRulesPreserveExternallyOwnedRowsAndColumns(): void
    {
        DB::table('copy_items')->insert([
            ['id' => 1, 'source' => 'external', 'name' => 'New label', 'external_value' => 'Active value'],
            ['id' => 2, 'source' => 'local', 'name' => 'New local', 'external_value' => 'Inserted value'],
            ['id' => 5, 'source' => 'external', 'name' => 'External import', 'external_value' => 'Not ours'],
        ]);
        DB::table('copy_items_sb')->insert([
            ['id' => 1, 'source' => 'external', 'name' => 'Old label', 'external_value' => 'Retained value'],
            ['id' => 3, 'source' => 'external', 'name' => 'Keep external', 'external_value' => 'External'],
            ['id' => 4, 'source' => 'local', 'name' => 'Remove local', 'external_value' => 'Obsolete'],
        ]);
        $table = new SandboxTable('copy_items', ['id'], reset: new SandboxCopyRules(
            updateColumns: ['name'],
            inserts: fn (Builder $query) => $query->where('source', 'local'),
            deletes: fn (Builder $query) => $query->where('source', 'local'),
        ));

        $table->resetSandbox(DB::connection());

        $this->assertSame([1, 2, 3], DB::table('copy_items_sb')->orderBy('id')->pluck('id')->all());
        $this->assertSame('New label', DB::table('copy_items_sb')->where('id', 1)->value('name'));
        $this->assertSame('Retained value', DB::table('copy_items_sb')->where('id', 1)->value('external_value'));
        $this->assertSame('Inserted value', DB::table('copy_items_sb')->where('id', 2)->value('external_value'));
    }

    #[Test]
    public function updateFilterCanDependOnSourceAndTargetState(): void
    {
        foreach ([1, 2] as $id) {
            DB::table('copy_items')->insert(['id' => $id, 'source' => 'local', 'name' => 'Active', 'external_value' => 'Value']);
            DB::table('copy_items_sb')->insert(['id' => $id, 'source' => $id === 1 ? 'local' : 'external', 'name' => 'Draft', 'external_value' => 'Value']);
        }
        $table = new SandboxTable('copy_items', ['id'], reset: new SandboxCopyRules(
            updates: fn (Builder $query) => $query->where('source.source', 'local')->where('target.source', 'local'),
        ));
        $table->resetSandbox(DB::connection());
        $this->assertSame(['Active', 'Draft'], DB::table('copy_items_sb')->orderBy('id')->pluck('name')->all());
    }

    #[Test]
    public function emptyUpdateColumnsKeepMatchedRowsAndCopyMissingOnes(): void
    {
        DB::table('copy_items')->insert([
            ['id' => 1, 'source' => 'local', 'name' => 'Active', 'external_value' => 'Value'],
            ['id' => 2, 'source' => 'local', 'name' => 'New', 'external_value' => 'Value'],
        ]);
        DB::table('copy_items_sb')->insert(['id' => 1, 'source' => 'local', 'name' => 'Retained', 'external_value' => 'Value']);
        $table = new SandboxTable('copy_items', ['id'], reset: new SandboxCopyRules(updateColumns: []));
        $table->resetSandbox(DB::connection());
        $this->assertSame(['Retained', 'New'], DB::table('copy_items_sb')->orderBy('id')->pluck('name')->all());
    }

    #[Test]
    public function customPreparationRunsOnlyForFreeDraftAndReplacesDefaultCopy(): void
    {
        DB::table('copy_items')->insert(['id' => 1, 'source' => 'local', 'name' => 'Active', 'external_value' => 'Value']);
        DB::table('copy_items_sb')->insert(['id' => 1, 'source' => 'local', 'name' => 'Retained', 'external_value' => 'Value']);
        $registry = new SandboxModelRegistry();
        $registry->registerTables(DB::connection(), new SandboxTable('copy_items', ['id']));
        $backend = new class($registry) extends EloquentSandboxBackend
        {
            public int $preparations = 0;

            protected function initializeDraft(): void
            {
                $this->preparations++;
                DB::table('copy_items_sb')->update(['name' => 'Custom']);
            }
        };
        $backend->open(1);
        $this->assertSame('Custom', DB::table('copy_items_sb')->value('name'));
        $backend->open(1);
        SandboxStatus::query()->update(['status' => 2]);
        $backend->open(2);
        $this->assertSame(1, $backend->preparations);
        $this->assertTrue($backend->status()->isLockedBy(2));
    }

    #[Test]
    public function failedPreparationRollsBackItsWritesAndDoesNotOpenDraft(): void
    {
        Event::fake([SandboxOpened::class]);
        $backend = new class(new SandboxModelRegistry()) extends EloquentSandboxBackend
        {
            protected function initializeDraft(): void
            {
                DB::table('copy_items_sb')->insert(['id' => 1, 'source' => 'local', 'name' => 'Partial', 'external_value' => 'Value']);

                throw new \RuntimeException('Preparation failed');
            }
        };

        try {
            $backend->open(1);
            $this->fail('Failed initialization opened draft.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Preparation failed', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('copy_items_sb')->count());
        $this->assertTrue($backend->status()->isFree());
        Event::assertNothingDispatched();
    }

    #[Test]
    public function selectiveResetRulesDoNotRestrictApplyingCompleteDraft(): void
    {
        DB::table('copy_items')->insert(['id' => 1, 'source' => 'local', 'name' => 'Old', 'external_value' => 'Old']);
        DB::table('copy_items_sb')->insert([
            ['id' => 1, 'source' => 'external', 'name' => 'Published', 'external_value' => 'Published'],
            ['id' => 2, 'source' => 'external', 'name' => 'New', 'external_value' => 'New'],
        ]);
        $table = new SandboxTable('copy_items', ['id'], reset: new SandboxCopyRules(
            updateColumns: [],
            inserts: fn (Builder $query) => $query->where('source', 'local'),
        ));
        $table->applySandbox(DB::connection());
        $this->assertSame(
            DB::table('copy_items_sb')->orderBy('id')->get()->toJson(),
            DB::table('copy_items')->orderBy('id')->get()->toJson(),
        );
    }
}
