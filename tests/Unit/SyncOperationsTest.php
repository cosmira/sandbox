<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Sandbox\HasSandbox;
use Packages\Sandbox\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SyncOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTables();
        SimpleModelStub::setSimpleKey();
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

    /**
     * Test that syncIntoSandbox removes orphaned rows (present in sandbox but not in active).
     */
    #[Test]
    public function syncIntoSandboxRemovesOrphans(): void
    {
        DB::table('items')->insert([
            ['name' => 'item1', 'value' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('items_sb')->insert([
            ['name' => 'orphan', 'value' => 999, 'created_at' => now(), 'updated_at' => now()],
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
        DB::table('items')->insert([
            ['id' => 1, 'name' => 'item1', 'value' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('items_sb')->insert([
            ['id' => 1, 'name' => 'item1', 'value' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);

        SimpleModelStub::syncIntoSandbox();

        $this->assertSame(100, DB::table('items_sb')->find(1)->value);
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
            ['name' => 'sbitem1', 'value' => 111, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('items')->insert([
            ['name' => 'orphan_active', 'value' => 999, 'created_at' => now(), 'updated_at' => now()],
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
}
