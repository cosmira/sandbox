<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxTableSynchronizer;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

final class SyncOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTables();
        SimpleModelStub::setSimpleKey();
        SimpleModelStub::setDefaultSyncColumns();
        SimpleModelStub::useActiveTable();
    }

    private function createTestTables(): void
    {
        Schema::dropIfExists('items_sb');
        Schema::dropIfExists('items');
        Schema::create('items', function ($table): void {
            $table->id();
            $table->string('name');
            $table->integer('value')->default(0);
            $table->timestamps();
        });
        Schema::create('items_sb', function ($table): void {
            $table->id();
            $table->string('name');
            $table->integer('value')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Test that syncIntoSandbox properly copies rows from active to sandbox.
     */
    #[Test]
    public function syncIntoSandboxCopiesRowsFromActiveToSandbox(): void
    {
        DB::table('items')->insert([
            ['name' => 'item1', 'value' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'item2', 'value' => 200, 'created_at' => now(), 'updated_at' => now()],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $this->assertSame(2, DB::table('items_sb')->count());
        $this->assertSame(100, DB::table('items_sb')->where('name', 'item1')->value('value'));
        $this->assertSame(200, DB::table('items_sb')->where('name', 'item2')->value('value'));
    }

    #[Test]
    public function syncIntoSandboxReplacesExistingRowsWhenNoTrackColumnIsConfigured(): void
    {
        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'fresh',
                'value'      => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('items_sb')->insert([
            [
                'id'         => 1,
                'name'       => 'stale',
                'value'      => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $this->assertSame(1, DB::table('items_sb')->count());
        $this->assertSame('fresh', DB::table('items_sb')->where('id', 1)->value('name'));
        $this->assertSame(100, DB::table('items_sb')->where('id', 1)->value('value'));
    }

    /**
     * Test that syncIntoSandbox removes orphaned rows (present in sandbox but not in active).
     */
    #[Test]
    public function syncIntoSandboxRemovesOrphans(): void
    {
        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('items_sb')->insert([
            [
                'id'         => 2,
                'name'       => 'orphan',
                'value'      => 999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $this->assertSame(1, DB::table('items_sb')->count());
        $this->assertSame(0, DB::table('items_sb')->where('name', 'orphan')->count());
    }

    /**
     * Test that syncIntoSandbox updates changed rows in sandbox.
     */
    #[Test]
    public function syncIntoSandboxUpdatesChangedRows(): void
    {
        SimpleModelStub::setTrackedChangeColumn();

        $old = now()->subDay();
        $new = now();
        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 100,
                'created_at' => $old,
                'updated_at' => $new,
            ],
        ]);
        DB::table('items_sb')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 50,
                'created_at' => $old,
                'updated_at' => $old,
            ],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $this->assertSame(100, DB::table('items_sb')->find(1)->value);
    }

    #[Test]
    public function syncIntoSandboxDoesNotUpdateRowsWhenTrackedColumnIsUnchanged(): void
    {
        SimpleModelStub::setTrackedChangeColumn();

        $timestamp = now();
        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 100,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id'         => 2,
                'name'       => 'item2',
                'value'      => 200,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);
        DB::table('items_sb')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 50,
                'created_at' => $timestamp,
                'updated_at' => $timestamp->copy()->subDay(),
            ],
            [
                'id'         => 2,
                'name'       => 'item2',
                'value'      => 999,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $this->assertSame(100, DB::table('items_sb')->where('id', 1)->value('value'));
        $this->assertSame(999, DB::table('items_sb')->where('id', 2)->value('value'));
    }

    #[Test]
    public function syncIntoSandboxUpdatesWhenTrackedColumnChangesFromNull(): void
    {
        SimpleModelStub::setTrackedChangeColumn();

        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('items_sb')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 50,
                'created_at' => now(),
                'updated_at' => null,
            ],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $this->assertSame(100, DB::table('items_sb')->where('id', 1)->value('value'));
    }

    #[Test]
    public function syncIntoSandboxUpdatesWhenTrackedColumnChangesToNull(): void
    {
        SimpleModelStub::setTrackedChangeColumn();

        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 100,
                'created_at' => now(),
                'updated_at' => null,
            ],
        ]);
        DB::table('items_sb')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $row = DB::table('items_sb')->where('id', 1)->first();
        $this->assertSame(100, $row->value);
        $this->assertNull($row->updated_at);
    }

    #[Test]
    public function syncIntoSandboxThrowsWhenTrackedColumnIsMissing(): void
    {
        SimpleModelStub::setMissingTrackedChangeColumn();

        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_SYNC_COLUMN_MISSING);

        SimpleModelStub::syncIntoSandbox();
    }

    /**
     * Test that syncIntoActive properly copies rows from sandbox to active.
     */
    #[Test]
    public function syncIntoActiveCopiesRowsFromSandboxToActive(): void
    {
        DB::table('items_sb')->insert([
            ['name' => 'sbitem1', 'value' => 111, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'sbitem2', 'value' => 222, 'created_at' => now(), 'updated_at' => now()],
        ]);

        SimpleModelStub::syncIntoActive();

        $this->assertSame(2, DB::table('items')->count());
        $this->assertSame(111, DB::table('items')->where('name', 'sbitem1')->value('value'));
        $this->assertSame(222, DB::table('items')->where('name', 'sbitem2')->value('value'));
    }

    /**
     * Test that syncIntoActive removes orphaned rows in active table.
     */
    #[Test]
    public function syncIntoActiveRemovesOrphansFromActive(): void
    {
        DB::table('items_sb')->insert([
            [
                'id'         => 1,
                'name'       => 'sbitem1',
                'value'      => 111,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('items')->insert([
            [
                'id'         => 2,
                'name'       => 'orphan_active',
                'value'      => 999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        SimpleModelStub::syncIntoActive();

        $this->assertSame(1, DB::table('items')->count());
        $this->assertSame(0, DB::table('items')->where('name', 'orphan_active')->count());
    }

    /**
     * Test that getSandboxPrimaryKey correctly returns the key for composite key model.
     */
    #[Test]
    public function getSandboxPrimaryKeyReturnsCorrectKeyForSingleKey(): void
    {
        $model = new SimpleModelStub();
        $key = $model->getSandboxPrimaryKey();

        $this->assertSame('id', $key);
    }

    #[Test]
    public function canSwitchBetweenActiveAndSandboxTables(): void
    {
        $model = new SimpleModelStub();

        $this->assertFalse(SimpleModelStub::isUsingSandboxTable());
        $this->assertSame('items', $model->getTableForQuery());

        SimpleModelStub::useSandboxTable();

        $this->assertTrue(SimpleModelStub::isUsingSandboxTable());
        $this->assertSame('items_sb', $model->getTableForQuery());

        SimpleModelStub::useActiveTable();

        $this->assertFalse(SimpleModelStub::isUsingSandboxTable());
        $this->assertSame('items', $model->getTableForQuery());
    }

    #[Test]
    public function withoutSandboxWritesToActiveTableWhileSandboxIsEnabled(): void
    {
        SimpleModelStub::useSandboxTable();

        SimpleModelStub::withoutSandbox(function (): void {
            $model = new SimpleModelStub();
            $model->forceFill([
                'name'  => 'active-only',
                'value' => 777,
            ])->save();
        });

        $this->assertTrue(SimpleModelStub::isUsingSandboxTable());
        $this->assertSame(777, DB::table('items')->where('name', 'active-only')->value('value'));
        $this->assertFalse(DB::table('items_sb')->where('name', 'active-only')->exists());
    }

    #[Test]
    public function withoutSandboxRestoresSandboxStateWhenCallbackFails(): void
    {
        SimpleModelStub::useSandboxTable();

        try {
            SimpleModelStub::withoutSandbox(function (): never {
                throw new \RuntimeException('Active write failed.');
            });
        } catch (\RuntimeException) {
            $this->assertTrue(SimpleModelStub::isUsingSandboxTable());

            return;
        }

        $this->fail('The failing callback did not throw.');
    }

    #[Test]
    public function withSandboxRestoresActiveStateAfterCallback(): void
    {
        SimpleModelStub::useActiveTable();

        SimpleModelStub::withSandbox(function (): void {
            $this->assertTrue(SimpleModelStub::isUsingSandboxTable());
        });

        $this->assertFalse(SimpleModelStub::isUsingSandboxTable());
    }

    #[Test]
    public function queryScopesCanTargetActiveAndSandboxTables(): void
    {
        $sandboxSql = SimpleModelStub::query()->sandbox()->toSql();
        $activeSql = SimpleModelStub::query()->active()->toSql();

        $this->assertStringContainsString('items_sb', $sandboxSql);
        $this->assertStringContainsString('items', $activeSql);
    }

    #[Test]
    public function modelReportsSandboxSyncSupport(): void
    {
        $this->assertTrue(SimpleModelStub::supportsSandboxSync());
    }

    #[Test]
    public function sandboxApiMethodsRemainPublic(): void
    {
        $methods = [
            'getActiveTable',
            'getTable',
            'getTableForQuery',
            'getSandboxTablePostfix',
            'getSandboxPrimaryKey',
            'getSandboxTable',
            'useSandboxTable',
            'useActiveTable',
            'isUsingSandboxTable',
            'withoutSandbox',
            'withSandbox',
            'syncIntoSandbox',
            'syncIntoActive',
            'getSandboxWritableColumns',
            'supportsSandboxSync',
        ];

        foreach ($methods as $method) {
            $reflection = new ReflectionMethod(SimpleModelStub::class, $method);

            $this->assertTrue($reflection->isPublic(), $method.' should stay public.');
        }
    }

    #[Test]
    public function resetSandboxDataSyncsModelClass(): void
    {
        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        app(Sandbox::class)->resetSandboxData(SimpleModelStub::class);

        $this->assertSame(1, DB::table('items_sb')->count());
        $this->assertSame(100, DB::table('items_sb')->where('id', 1)->value('value'));
    }

    #[Test]
    public function resetSandboxDataRejectsNonModelClasses(): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        app(Sandbox::class)->resetSandboxData(\stdClass::class);
    }

    #[Test]
    public function resetSandboxDataRejectsModelClassesWithoutSandboxSync(): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        app(Sandbox::class)->resetSandboxData(NonSandboxSyncModelStub::class);
    }

    #[Test]
    public function resetSandboxDataRejectsModelInstancesWithoutSandboxTables(): void
    {
        $model = new PartialSandboxSyncModelStub();
        $model->id = 1;

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        app(Sandbox::class)->resetSandboxData($model);
    }

    #[Test]
    public function resetSandboxDataRejectsModelsWithoutWritableColumnApi(): void
    {
        $model = new PartialSandboxTableModelStub();
        $model->id = 1;

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        app(Sandbox::class)->resetSandboxData($model);
    }

    #[Test]
    public function resetSandboxDataInsertsSingleModelRow(): void
    {
        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $model = new SimpleModelStub();
        $model->id = 1;

        app(Sandbox::class)->resetSandboxData($model);

        $this->assertSame(100, DB::table('items_sb')->where('id', 1)->value('value'));
    }

    #[Test]
    public function resetSandboxDataUpdatesSingleModelRow(): void
    {
        DB::table('items')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 200,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('items_sb')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $model = new SimpleModelStub();
        $model->id = 1;

        app(Sandbox::class)->resetSandboxData($model);

        $this->assertSame(200, DB::table('items_sb')->where('id', 1)->value('value'));
    }

    #[Test]
    public function resetSandboxDataDeletesSingleModelRowWhenActiveRowIsMissing(): void
    {
        DB::table('items_sb')->insert([
            [
                'id'         => 1,
                'name'       => 'item1',
                'value'      => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $model = new SimpleModelStub();
        $model->id = 1;

        app(Sandbox::class)->resetSandboxData($model);

        $this->assertFalse(DB::table('items_sb')->where('id', 1)->exists());
        $this->assertSame(0, DB::table('items_sb')->count());
    }

    /**
     * Test that empty sandbox can sync into active.
     */
    #[Test]
    public function syncIntoActiveWithEmptySandboxClearsActive(): void
    {
        DB::table('items')->insert([
            ['name' => 'item1', 'value' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);

        SimpleModelStub::syncIntoActive();

        $this->assertSame(0, DB::table('items')->count());
    }

    #[Test]
    public function syncIntoSandboxHonorsExplicitSyncColumns(): void
    {
        SimpleModelStub::setNameOnlySyncColumns();

        DB::table('items')->insert([
            [
                'id'         => 5,
                'name'       => 'copied-name',
                'value'      => 999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $row = DB::table('items_sb')->where('id', 5)->first();

        $this->assertSame('copied-name', $row->name);
        $this->assertSame(0, $row->value);
    }

    /**
     * Test that empty active can sync into sandbox.
     */
    #[Test]
    public function syncIntoSandboxWithEmptyActiveClearsSandbox(): void
    {
        DB::table('items_sb')->insert([
            ['name' => 'item1', 'value' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $this->assertSame(0, DB::table('items_sb')->count());
    }

    #[Test]
    public function synchronizerUsesSchemaColumnsWhenColumnsAreNotProvided(): void
    {
        DB::table('items')->insert([
            [
                'id'         => 10,
                'name'       => 'schema-columns',
                'value'      => 321,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $synchronizer = new SandboxTableSynchronizer();

        $synchronizer->sync(
            sourceTable: 'items',
            targetTable: 'items_sb',
            keyColumns: ['id'],
            columns: [],
            changeColumn: null,
        );

        $row = DB::table('items_sb')->where('id', 10)->first();

        $this->assertSame('schema-columns', $row->name);
        $this->assertSame(321, $row->value);
    }

    #[Test]
    public function synchronizerSkipsInsertionWhenNoColumnsCanBeResolved(): void
    {
        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('items')
            ->andReturn([]);

        try {
            $synchronizer = new SandboxTableSynchronizer();

            $synchronizer->sync(
                sourceTable: 'items',
                targetTable: 'items_sb',
                keyColumns: ['id'],
                columns: [],
                changeColumn: null,
            );

            $this->assertSame(0, DB::table('items_sb')->count());
        } finally {
            Schema::swap(DB::connection()->getSchemaBuilder());
        }
    }

    #[Test]
    public function synchronizerInsertsMissingRowsInPortableChunks(): void
    {
        $now = now();
        $rows = [];

        for ($id = 1; $id <= 501; $id++) {
            $rows[] = [
                'id'         => $id,
                'name'       => 'item-'.$id,
                'value'      => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('items')->insert($rows);

        $synchronizer = new SandboxTableSynchronizer();

        $synchronizer->sync(
            sourceTable: 'items',
            targetTable: 'items_sb',
            keyColumns: ['id'],
            columns: ['id', 'name', 'value', 'created_at', 'updated_at'],
            changeColumn: null,
        );

        $this->assertSame(501, DB::table('items_sb')->count());
        $this->assertSame(500, DB::table('items_sb')->where('id', 500)->value('value'));
        $this->assertSame(501, DB::table('items_sb')->where('id', 501)->value('value'));
    }
}

class SimpleModelStub extends Model
{
    use HasSandbox;

    protected $table = 'items';

    public $timestamps = true;

    public static function setSimpleKey(): void
    {
        self::$sandboxPrimaryKey = 'id';
        self::$sandboxTrackChangeColumn = null;
    }

    public static function setDefaultSyncColumns(): void
    {
        self::$sandboxSyncColumns = null;
    }

    public static function setNameOnlySyncColumns(): void
    {
        self::$sandboxPrimaryKey = 'id';
        self::$sandboxTrackChangeColumn = null;
        self::$sandboxSyncColumns = ['id', 'name'];
    }

    public static function setTrackedChangeColumn(): void
    {
        self::$sandboxPrimaryKey = 'id';
        self::$sandboxTrackChangeColumn = 'updated_at';
    }

    public static function setMissingTrackedChangeColumn(): void
    {
        self::$sandboxPrimaryKey = 'id';
        self::$sandboxTrackChangeColumn = 'missing_column';
    }
}

class NonSandboxSyncModelStub extends Model
{
    protected $table = 'items';
}

class PartialSandboxSyncModelStub extends Model
{
    protected $table = 'items';

    public static function syncIntoSandbox(): void {}
}

class PartialSandboxTableModelStub extends Model
{
    protected $table = 'items';

    public static function syncIntoSandbox(): void {}

    public function getActiveTable(): string
    {
        return 'items';
    }

    public function getSandboxTable(): string
    {
        return 'items_sb';
    }

    public function getSandboxPrimaryKey(): string
    {
        return 'id';
    }
}
