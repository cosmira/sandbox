<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class HasSandboxCompositeKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTables();
        PivotModelStub::setCompositeKey();
    }

    private function createTestTables(): void
    {
        Schema::dropIfExists('test_pivot_sb');
        Schema::dropIfExists('test_pivot');
        Schema::create('test_pivot', function ($table): void {
            $table->string('a', 10);
            $table->string('b', 10);
            $table->string('value', 50)->nullable();
            $table->primary(['a', 'b']);
        });
        Schema::create('test_pivot_sb', function ($table): void {
            $table->string('a', 10);
            $table->string('b', 10);
            $table->string('value', 50)->nullable();
            $table->primary(['a', 'b']);
        });
    }

    #[Test]
    public function resetSandboxWithCompositeKeyCopiesRows(): void
    {
        DB::table('test_pivot')->insert(['a' => 'x', 'b' => 'y', 'value' => 'v1']);
        DB::table('test_pivot')->insert(['a' => 'p', 'b' => 'q', 'value' => 'v2']);

        PivotModelStub::resetSandbox();

        $this->assertSame(2, DB::table('test_pivot_sb')->count());
        $this->assertSame(
            'v1',
            DB::table('test_pivot_sb')
                ->where(['a' => 'x', 'b' => 'y'])
                ->value('value'),
        );
        $this->assertSame(
            'v2',
            DB::table('test_pivot_sb')
                ->where(['a' => 'p', 'b' => 'q'])
                ->value('value'),
        );
    }

    #[Test]
    public function resetSandboxWithCompositeKeyRemovesOrphans(): void
    {
        DB::table('test_pivot')->insert(['a' => 'x', 'b' => 'y', 'value' => 'v1']);
        DB::table('test_pivot_sb')->insert(['a' => 'orphan', 'b' => 'sb', 'value' => 'old']);

        PivotModelStub::resetSandbox();

        $this->assertSame(1, DB::table('test_pivot_sb')->count());
        $this->assertNotInstanceOf(
            \stdClass::class,
            DB::table('test_pivot_sb')
                ->where(['a' => 'orphan', 'b' => 'sb'])
                ->first(),
        );
    }

    #[Test]
    public function applySandboxWithCompositeKeyCopiesRows(): void
    {
        DB::table('test_pivot_sb')->insert(['a' => 'x', 'b' => 'y', 'value' => 'from_sb']);

        PivotModelStub::applySandbox();

        $this->assertSame(1, DB::table('test_pivot')->count());
        $this->assertSame(
            'from_sb',
            DB::table('test_pivot')
                ->where(['a' => 'x', 'b' => 'y'])
                ->value('value'),
        );
    }

    #[Test]
    public function getSandboxPrimaryKeyReturnsArrayForCompositeKey(): void
    {
        $model = new PivotModelStub();
        $key = $model->getSandboxPrimaryKey();
        $this->assertIsArray($key);
        $this->assertSame(['a', 'b'], $key);
    }

    #[Test]
    public function resetSingleRecordReturnsWhenCompositeKeyIsIncomplete(): void
    {
        DB::table('test_pivot_sb')->insert(['a' => 'x', 'b' => 'y', 'value' => 'old']);

        $model = new PivotModelStub();
        $model->a = 'x';

        app(Sandbox::class)->resetSandboxData($model);

        $this->assertSame(1, DB::table('test_pivot_sb')->count());
        $this->assertSame(
            'old',
            DB::table('test_pivot_sb')
                ->where(['a' => 'x', 'b' => 'y'])
                ->value('value'),
        );
    }

    #[Test]
    public function resetSingleRecordUsesOnlyCompositeKeyColumnsForLookup(): void
    {
        DB::table('test_pivot')->insert(['a' => 'x', 'b' => 'y', 'value' => 'fresh']);
        DB::table('test_pivot_sb')->insert(['a' => 'x', 'b' => 'y', 'value' => 'stale']);

        $model = new PivotModelStub();
        $model->a = 'x';
        $model->b = 'y';
        $model->value = 'stale';

        app(Sandbox::class)->resetSandboxData($model);

        $this->assertSame(
            'fresh',
            DB::table('test_pivot_sb')
                ->where(['a' => 'x', 'b' => 'y'])
                ->value('value'),
        );
    }
}

class PivotModelStub extends Model
{
    use HasSandbox;

    protected $table = 'test_pivot';

    public static function setCompositeKey(): void
    {
        self::$sandboxPrimaryKey = ['a', 'b'];
        self::$sandboxTrackChangeColumn = null;
    }
}
