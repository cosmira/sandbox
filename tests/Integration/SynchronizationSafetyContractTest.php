<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Support\SandboxTableSynchronizer;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class SynchronizationSafetyContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['sync_contract', 'sync_contract_sb'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->integer('id')->primary();
                $blueprint->string('value')->nullable();
                $blueprint->dateTime('change_date')->nullable();
            });
        }
        Schema::create('sync_contract_children', function (Blueprint $blueprint): void {
            $blueprint->integer('id')->primary();
            $blueprint->integer('parent_id');
            $blueprint->foreign('parent_id')->references('id')->on('sync_contract_sb')->cascadeOnDelete();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sync_contract_children');
        Schema::dropIfExists('sync_contract_sb');
        Schema::dropIfExists('sync_contract');
        parent::tearDown();
    }

    #[Test]
    public function synchronizationDoesNotDiscardChangesAtEqualTimestampPrecision(): void
    {
        $timestamp = '2026-09-09 10:00:00';
        DB::table('sync_contract')->insert(['id' => 1, 'value' => 'new', 'change_date' => $timestamp]);
        DB::table('sync_contract_sb')->insert(['id' => 1, 'value' => 'old', 'change_date' => $timestamp]);
        $this->sync('change_date');
        $this->assertSame('new', DB::table('sync_contract_sb')->where('id', 1)->value('value'));
    }

    #[Test]
    public function updatingExistingRowsDoesNotDeleteTheirChildren(): void
    {
        DB::table('sync_contract')->insert(['id' => 1, 'value' => 'new']);
        DB::table('sync_contract_sb')->insert(['id' => 1, 'value' => 'old']);
        DB::table('sync_contract_children')->insert(['id' => 1, 'parent_id' => 1]);
        $this->sync(null);
        $this->assertSame('new', DB::table('sync_contract_sb')->where('id', 1)->value('value'));
        $this->assertSame(1, DB::table('sync_contract_children')->count());
    }

    #[Test]
    public function failedUpdateAlsoRollsBackEarlierOrphanDeletion(): void
    {
        Schema::table('sync_contract_sb', fn (Blueprint $table) => $table->unique('value'));
        DB::table('sync_contract')->insert([
            ['id' => 1, 'value' => 'conflict'], ['id' => 2, 'value' => 'conflict'],
        ]);
        DB::table('sync_contract_sb')->insert([
            ['id' => 1, 'value' => 'original'], ['id' => 2, 'value' => 'conflict'],
            ['id'                                     => 99, 'value' => 'orphan'],
        ]);
        $before = DB::table('sync_contract_sb')->orderBy('id')->get()->all();

        try {
            $this->sync(null);
            $this->fail('Expected a real unique constraint failure.');
        } catch (QueryException) {
            $this->assertEquals($before, DB::table('sync_contract_sb')->orderBy('id')->get()->all());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function usesItsInjectedConnectionEvenWhenTheDefaultChanges(): void
    {
        $connection = DB::connection();
        DB::table('sync_contract')->insert(['id' => 1, 'value' => 'explicit connection']);
        config(['database.connections.decoy' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::setDefaultConnection('decoy');

        try {
            (new SandboxTableSynchronizer($connection))->sync(
                'sync_contract', 'sync_contract_sb', ['id'], [], null,
            );
            $this->assertSame('explicit connection', $connection->table('sync_contract_sb')->value('value'));
            $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('sync_contract_sb'));
        } finally {
            DB::setDefaultConnection('testing');
            DB::purge('decoy');
        }
    }

    #[Test]
    public function equalTimestampsStillCopyCaseOnlyChangesAndNulls(): void
    {
        DB::table('sync_contract')->insert([
            ['id' => 1, 'value' => 'NEW'], ['id' => 2, 'value' => null],
        ]);
        DB::table('sync_contract_sb')->insert([
            ['id' => 1, 'value' => 'new'], ['id' => 2, 'value' => 'remove'],
        ]);
        $this->sync('change_date');
        $this->assertSame(['NEW', null], DB::table('sync_contract_sb')->orderBy('id')->pluck('value')->all());
    }

    private function sync(?string $changeColumn): void
    {
        (new SandboxTableSynchronizer(DB::connection()))->sync(
            sourceTable: 'sync_contract',
            targetTable: 'sync_contract_sb',
            keyColumns: ['id'],
            columns: ['id', 'value', 'change_date'],
            changeColumn: $changeColumn,
        );
    }
}
