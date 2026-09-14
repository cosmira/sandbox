<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\SandboxCopyRules;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class TreeTableContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['tree_nodes', 'tree_nodes_sb'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->integer('id');
                $table->primary('id');
                $table->integer('parent_id')->nullable();
                $table->string('name');
                $table->foreign('parent_id')->references('id')->on($name)->cascadeOnDelete();
            });
        }
        app(Sandbox::class)->tables(new SandboxTable('tree_nodes', ['id'], parentColumn: 'parent_id'));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tree_nodes_sb');
        Schema::dropIfExists('tree_nodes');
        parent::tearDown();
    }

    #[Test]
    public function openingInsertsNewParentBeforeUpdatingExistingChild(): void
    {
        foreach (['tree_nodes', 'tree_nodes_sb'] as $name) {
            DB::table($name)->insert(['id' => 1, 'parent_id' => null, 'name' => 'Child']);
        }
        DB::table('tree_nodes')->insert(['id' => 2, 'parent_id' => null, 'name' => 'New parent']);
        DB::table('tree_nodes')->where('id', 1)->update(['parent_id' => 2]);
        app(Sandbox::class)->open(1);
        $this->assertSame(2, DB::table('tree_nodes_sb')->where('id', 1)->value('parent_id'));
        $this->assertSame(2, DB::table('tree_nodes_sb')->count());
    }

    #[Test]
    public function openingCopiesParentsBeforeChildrenAcrossInsertChunks(): void
    {
        DB::table('tree_nodes')->insert(['id' => 1000, 'parent_id' => null, 'name' => 'Root']);
        DB::table('tree_nodes')->insert(['id' => 999, 'parent_id' => 1000, 'name' => 'Parent']);
        for ($id = 1; $id <= 510; $id++) {
            DB::table('tree_nodes')->insert(['id' => $id, 'parent_id' => 999, 'name' => 'Child '.$id]);
        }
        app(Sandbox::class)->open(1);
        $this->assertSame(512, DB::table('tree_nodes_sb')->count());
        $this->assertSame(999, DB::table('tree_nodes_sb')->where('id', 1)->value('parent_id'));
    }

    #[Test]
    public function deletingObsoleteParentDoesNotLoseAReparentedChild(): void
    {
        DB::table('tree_nodes_sb')->insert(['id' => 3, 'parent_id' => null, 'name' => 'Obsolete parent']);
        DB::table('tree_nodes_sb')->insert(['id' => 1, 'parent_id' => 3, 'name' => 'Child']);
        DB::table('tree_nodes')->insert(['id' => 2, 'parent_id' => null, 'name' => 'New parent']);
        DB::table('tree_nodes')->insert(['id' => 1, 'parent_id' => 2, 'name' => 'Child']);
        app(Sandbox::class)->open(1);
        $this->assertSame([1, 2], DB::table('tree_nodes_sb')->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, DB::table('tree_nodes_sb')->where('id', 1)->value('parent_id'));
    }

    #[Test]
    public function cyclicSourceFailsAndRollsBackDeletedDraftRows(): void
    {
        DB::table('tree_nodes')->insert(['id' => 1, 'parent_id' => null, 'name' => 'First']);
        DB::table('tree_nodes')->insert(['id' => 2, 'parent_id' => 1, 'name' => 'Second']);
        DB::table('tree_nodes')->where('id', 1)->update(['parent_id' => 2]);
        DB::table('tree_nodes_sb')->insert(['id' => 3, 'parent_id' => null, 'name' => 'Old draft']);

        try {
            app(Sandbox::class)->open(1);
            $this->fail('Cyclic initialization succeeded.');
        } catch (SandboxException $exception) {
            $this->assertStringContainsString('cycle', $exception->getMessage());
        }

        $this->assertSame([3], DB::table('tree_nodes_sb')->pluck('id')->all());
        $this->assertTrue(app(Sandbox::class)->status()->isFree());
    }

    #[Test]
    public function selfReferencingRootCanBeCopied(): void
    {
        DB::table('tree_nodes')->insert(['id' => 1, 'parent_id' => 1, 'name' => 'Root']);
        app(Sandbox::class)->open(1);
        $this->assertSame(1, DB::table('tree_nodes_sb')->value('parent_id'));
    }

    #[Test]
    public function reparentingPreservesColumnsExcludedFromUpdatesBeforeDeletingOldParent(): void
    {
        DB::table('tree_nodes_sb')->insert(['id' => 3, 'parent_id' => null, 'name' => 'Obsolete parent']);
        DB::table('tree_nodes_sb')->insert(['id' => 1, 'parent_id' => 3, 'name' => 'Retained label']);
        DB::table('tree_nodes')->insert(['id' => 2, 'parent_id' => null, 'name' => 'New parent']);
        DB::table('tree_nodes')->insert(['id' => 1, 'parent_id' => 2, 'name' => 'Active label']);
        $table = new SandboxTable('tree_nodes', ['id'], parentColumn: 'parent_id', reset: new SandboxCopyRules(
            updateColumns: ['parent_id'],
        ));
        $table->resetSandbox(DB::connection());
        $this->assertSame([1, 2], DB::table('tree_nodes_sb')->orderBy('id')->pluck('id')->all());
        $this->assertSame('Retained label', DB::table('tree_nodes_sb')->where('id', 1)->value('name'));
        $this->assertSame(2, DB::table('tree_nodes_sb')->where('id', 1)->value('parent_id'));
    }
}
