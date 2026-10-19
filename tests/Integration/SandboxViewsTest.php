<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Support\SandboxRecordRestorer;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;

final class SandboxViewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['view_nodes', 'view_nodes_sb'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->integer('parent_id')->nullable();
                $table->string('name');
            });
        }

        DB::table('view_nodes')->insert([
            ['id' => 1, 'parent_id' => null, 'name' => 'Active root'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Active child'],
        ]);
        DB::table('view_nodes_sb')->insert([
            ['id' => 1, 'parent_id' => null, 'name' => 'Draft root'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'Draft child'],
            ['id' => 4, 'parent_id' => 1, 'name' => 'Another draft child'],
        ]);

        DB::statement('CREATE VIEW node_paths AS SELECT * FROM view_nodes');
        DB::statement('CREATE VIEW node_paths_sb AS SELECT * FROM view_nodes_sb');

        $registry = app(SandboxModelRegistry::class);
        $registry->register(ViewNode::class, NodePath::class);
        $registry->registerTables(DB::connection(), SandboxTable::view('node_links', 'node_links_draft'));
        DB::statement('CREATE VIEW node_links AS SELECT parent_id, id AS child_id FROM view_nodes');
        DB::statement('CREATE VIEW node_links_draft AS SELECT parent_id, id AS child_id FROM view_nodes_sb');
    }

    #[Test]
    #[TestWith([false, ['Active child']])]
    #[TestWith([true, ['Draft child', 'Another draft child']])]
    public function relationsFollowTheParentCopyDespiteTheGlobalSelection(bool $draft, array $names): void
    {
        $draft ? NodePath::useActive() : NodePath::useSandbox();
        $draft ? ViewNode::useActive() : ViewNode::useSandbox();
        $query = $draft ? ViewNode::sandbox() : ViewNode::active();
        $root = $query->with(['paths', 'descendants'])->withCount('paths')->findOrFail(1);

        $this->assertSame($names, $root->paths->pluck('name')->all());
        $this->assertSame($names, $root->descendants->pluck('name')->all());
        $this->assertSame(count($names), $root->paths_count);
        $this->assertSame($names, $root->paths()->orderBy('id')->pluck('name')->all());

        $query = $draft ? ViewNode::sandbox() : ViewNode::active();
        $this->assertSame([1], $query->whereHas('paths', fn ($paths) => $paths->where('name', $names[0]))->pluck('id')->all());
    }

    #[Test]
    #[TestWith([false, 'Active root'])]
    #[TestWith([true, 'Draft root'])]
    public function selfJoinsKeepTheSelectedViewCopy(bool $draft, string $name): void
    {
        $draft ? NodePath::useActive() : NodePath::useSandbox();
        $query = $draft ? NodePath::sandbox() : NodePath::active();

        $this->assertSame([$name], $query->whereHas('children')->pluck('name')->all());
    }

    #[Test]
    #[TestWith([false, 'Active root'])]
    #[TestWith([true, 'Draft root'])]
    public function nestedSelfJoinsPreserveTheViewSelection(bool $draft, string $name): void
    {
        DB::table('view_nodes')->insert(['id' => 5, 'parent_id' => 2, 'name' => 'Active grandchild']);
        DB::table('view_nodes_sb')->insert(['id' => 6, 'parent_id' => 3, 'name' => 'Draft grandchild']);
        $draft ? NodePath::useActive() : NodePath::useSandbox();
        $query = $draft ? NodePath::sandbox() : NodePath::active();

        $this->assertSame([$name], $query->whereHas('children.children')->pluck('name')->all());
    }

    #[Test]
    #[TestWith([false, 'Active grandchild'])]
    #[TestWith([true, 'Draft grandchild'])]
    public function throughRelationsKeepBothModelsOnTheParentCopy(bool $draft, string $name): void
    {
        DB::table('view_nodes')->insert(['id' => 5, 'parent_id' => 2, 'name' => 'Active grandchild']);
        DB::table('view_nodes_sb')->insert(['id' => 6, 'parent_id' => 3, 'name' => 'Draft grandchild']);
        $draft ? ViewNode::useActive() : ViewNode::useSandbox();
        $draft ? NodePath::useActive() : NodePath::useSandbox();
        $query = $draft ? ViewNode::sandbox() : ViewNode::active();

        $root = $query->with('grandchildren')->withCount('grandchildren')->findOrFail(1);

        $this->assertSame([$name], $root->grandchildren->pluck('name')->all());
        $this->assertSame([$name], $root->grandchildren()->get()->pluck('name')->all());
        $this->assertSame(1, $root->grandchildren_count);
    }

    #[Test]
    public function ordinaryRelationsKeepTheirOwnTables(): void
    {
        $root = ViewNode::sandbox()->findOrFail(1);

        $this->assertSame(['Active child'], $root->ordinaryChildren->pluck('name')->all());
    }

    #[Test]
    public function relationsRetainExplicitConnections(): void
    {
        config()->set('database.connections.view_archive', config('database.connections.testing'));
        $archive = DB::connection('view_archive');
        $archive->getSchemaBuilder()->create('archived_nodes', function (Blueprint $table): void {
            $table->integer('id');
            $table->integer('parent_id');
            $table->string('name');
        });
        $archive->table('archived_nodes')->insert([
            'id'        => 10,
            'parent_id' => 1,
            'name'      => 'Archived child',
        ]);

        $root = ViewNode::sandbox()->findOrFail(1);

        $this->assertSame(['Archived child'], $root->archivedChildren->pluck('name')->all());
    }

    #[Test]
    public function lifecycleCopiesTablesAndLeavesViewsAlone(): void
    {
        $registry = app(SandboxModelRegistry::class);
        $registry->resetSandbox();
        $this->assertSame(['Active root', 'Active child'], NodePath::sandbox()->orderBy('id')->pluck('name')->all());

        ViewNode::sandbox()->whereKey(2)->update(['name' => 'Published child']);
        $registry->applySandbox();
        $this->assertSame('Published child', NodePath::active()->findOrFail(2)->name);
    }

    #[Test]
    #[TestWith(['resetSandbox'])]
    #[TestWith(['applySandbox'])]
    public function viewDefinitionsRejectDirectSynchronization(string $operation): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Sandbox views cannot be synchronized');

        SandboxTable::view('node_links', 'node_links_draft')->$operation(DB::connection());
    }

    #[Test]
    #[TestWith(['resetSandbox'])]
    #[TestWith(['applySandbox'])]
    public function viewModelsRejectDirectSynchronization(string $operation): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Sandbox views cannot be synchronized');

        NodePath::$operation();
    }

    #[Test]
    public function individualViewRecordsCannotBeReset(): void
    {
        $path = NodePath::active()->findOrFail(1);
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Sandbox views cannot be synchronized');

        (new SandboxRecordRestorer())->restore($path);
    }

    #[Test]
    public function viewPivotsCannotBeSynchronizedAcrossCopies(): void
    {
        $root = ViewNode::active()->findOrFail(1);
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Sandbox views cannot be synchronized');

        $root->descendants()->syncAcrossCopies([]);
    }
}

class ViewNode extends Model
{
    use HasSandbox;

    protected $table = 'view_nodes';

    public $timestamps = false;

    public function paths(): HasMany
    {
        return $this->hasMany(NodePath::class, 'parent_id')->orderBy('id');
    }

    public function grandchildren(): HasManyThrough
    {
        return $this->hasManyThrough(NodePath::class, self::class, 'parent_id', 'parent_id');
    }

    public function ordinaryChildren(): HasMany
    {
        return $this->hasMany(OrdinaryNode::class, 'parent_id');
    }

    public function archivedChildren(): HasMany
    {
        return $this->hasMany(ArchivedNode::class, 'parent_id');
    }

    public function descendants(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'node_links', 'parent_id', 'child_id')->orderBy('id');
    }
}

class NodePath extends Model
{
    use HasSandbox;

    protected $table = 'node_paths';

    public $timestamps = false;

    public static function supportsSandboxSync(): bool
    {
        return false;
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}

class OrdinaryNode extends Model
{
    protected $table = 'view_nodes';
}

class ArchivedNode extends Model
{
    protected $table = 'archived_nodes';

    protected $connection = 'view_archive';
}
