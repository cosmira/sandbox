<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class EloquentIsolationContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IsolationItem::useActive();
        foreach (['isolation_items', 'isolation_items_sb', 'isolation_items_draft'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->integer('id')->primary();
                $blueprint->integer('parent_id')->nullable();
                $blueprint->string('name');
            });
        }
        DB::table('isolation_items')->insert(['id' => 1, 'name' => 'active']);
        DB::table('isolation_items_sb')->insert([
            ['id' => 1, 'parent_id' => null, 'name' => 'draft'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'child'],
        ]);
    }

    protected function tearDown(): void
    {
        IsolationItem::useActive();
        CustomSuffixIsolationItem::useActive();
        Schema::dropIfExists('isolation_items_draft');
        Schema::dropIfExists('isolation_items_sb');
        Schema::dropIfExists('isolation_items');
        parent::tearDown();
    }

    #[Test]
    public function hydratedDraftStaysBoundToDraftAfterContextExit(): void
    {
        $draft = IsolationItem::withSandbox(fn () => IsolationItem::findOrFail(1));
        $this->assertSame('isolation_items_sb', $draft->getTable());
        $this->assertSame('isolation_items', $draft->getActiveTable());
        $draft->name = 'edited';
        $draft->save();
        $this->assertSame('edited', $draft->refresh()->name);
        $this->assertSame('active', DB::table('isolation_items')->value('name'));
        $this->assertSame('edited', DB::table('isolation_items_sb')->where('id', 1)->value('name'));
        $this->assertFalse(IsolationItem::isUsingSandbox());
    }

    #[Test]
    public function explicitScopesBindHydratedModelsToTheSelectedLayer(): void
    {
        IsolationItem::sandbox()->findOrFail(1)->update(['name' => 'draft update']);
        IsolationItem::withSandbox(fn () => IsolationItem::active()->findOrFail(1)->update(['name' => 'active update']));
        $this->assertSame('draft update', DB::table('isolation_items_sb')->where('id', 1)->value('name'));
        $this->assertSame('active update', DB::table('isolation_items')->value('name'));
    }

    #[Test]
    public function selfJoinAliasesAreNotSuffixedAgain(): void
    {
        $items = IsolationItem::withSandbox(fn () => IsolationItem::whereHas('children')->with('children')->get());
        $this->assertSame([1], $items->modelKeys());
        $this->assertSame([2], $items->first()->children->modelKeys());
        $this->assertSame('isolation_items_sb', $items->first()->children->first()->getTable());
    }

    #[Test]
    public function nestedContextsRestoreAfterAnException(): void
    {
        IsolationItem::withSandbox(function (): void {
            try {
                IsolationItem::withoutSandbox(function (): never {
                    $this->assertSame('active', IsolationItem::findOrFail(1)->name);

                    throw new \RuntimeException('failure');
                });
            } catch (\RuntimeException) {
                $this->assertSame('draft', IsolationItem::findOrFail(1)->name);
            }
        });
        $this->assertFalse(IsolationItem::isUsingSandbox());
    }

    #[Test]
    public function customSuffixIsPreservedThroughHydrationAndSave(): void
    {
        DB::table('isolation_items_draft')->insert(['id' => 1, 'name' => 'custom draft']);
        $draft = CustomSuffixIsolationItem::withSandbox(fn () => CustomSuffixIsolationItem::findOrFail(1));
        $this->assertSame('isolation_items_draft', $draft->getTable());
        $this->assertSame('isolation_items_draft', $draft->getSandboxTable());
        $draft->update(['name' => 'updated custom draft']);
        $this->assertSame('updated custom draft', $draft->refresh()->name);
        $this->assertSame('active', DB::table('isolation_items')->value('name'));
    }

    #[Test]
    public function activeHydrationCannotBeRedirectedByALaterDraftContext(): void
    {
        $active = IsolationItem::findOrFail(1);
        IsolationItem::withSandbox(fn () => $active->update(['name' => 'updated active']));
        $this->assertSame('updated active', DB::table('isolation_items')->value('name'));
        $this->assertSame('draft', DB::table('isolation_items_sb')->where('id', 1)->value('name'));
    }

    #[Test]
    public function synchronizationUsesTheModelConnectionForSchemaAndWrites(): void
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('unconfigured_connection');

        try {
            SelectedConnectionIsolationItem::resetSandbox();
        } finally {
            DB::setDefaultConnection($default);
        }

        $this->assertSame('active', DB::table('isolation_items_sb')->where('id', 1)->value('name'));
        $this->assertSame(1, DB::table('isolation_items_sb')->count());
    }
}

class IsolationItem extends Model
{
    use HasSandbox;

    protected $table = 'isolation_items';

    protected $guarded = [];

    public $timestamps = false;

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}

class CustomSuffixIsolationItem extends IsolationItem
{
    public function getSandboxTablePostfix(): string
    {
        return '_draft';
    }
}

class SelectedConnectionIsolationItem extends IsolationItem
{
    protected $connection = 'testing';

    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return null;
    }
}
