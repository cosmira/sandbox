<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class PivotTableContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TableMember::useActive();
        TableRole::useActive();

        foreach (['', '_sb'] as $suffix) {
            foreach (['table_members', 'table_roles'] as $table) {
                Schema::create($table.$suffix, function (Blueprint $table): void {
                    $table->integer('id')->primary();
                    $table->string('name');
                });
            }
            Schema::create('table_memberships'.$suffix, function (Blueprint $table): void {
                $table->integer('member_id');
                $table->integer('role_id');
                $table->integer('weight');
                $table->primary(['member_id', 'role_id']);
            });
        }
        TableMember::insert([['id' => 1, 'name' => 'member'], ['id' => 2, 'name' => 'other']]);
        TableRole::insert([['id' => 1, 'name' => 'reader'], ['id' => 2, 'name' => 'editor']]);
        DB::table('table_memberships')->insert(['member_id' => 1, 'role_id' => 1, 'weight' => 10]);
    }

    protected function tearDown(): void
    {
        TableMember::useActive();
        TableRole::useActive();
        foreach (['membership_draft', 'table_memberships_sb', 'table_memberships', 'table_roles_sb', 'table_roles', 'table_members_sb', 'table_members'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function sandbox(): Sandbox
    {
        $sandbox = app(Sandbox::class);
        $sandbox->models(TableMember::class, TableRole::class);
        $sandbox->tables(new SandboxTable('table_memberships', ['member_id', 'role_id']));

        return $sandbox;
    }

    #[Test]
    public function standardRelationsEditOnlyDraftAndCommitCompositeKeys(): void
    {
        $owner = $this->createUser();
        $sandbox = $this->sandbox();
        $sandbox->edit($owner, function (): void {
            $member = TableMember::findOrFail(1);
            $member->roles()->attach(2, ['weight' => 20]);
            $member->roles()->updateExistingPivot(1, ['weight' => 11]);
            TableMember::findOrFail(2)->roles()->attach(1, ['weight' => 30]);
            $this->assertSame([1, 2], $member->roles()->orderBy('id')->get()->modelKeys());
            $this->assertSame([1, 2], TableRole::findOrFail(1)->members()->orderBy('id')->get()->modelKeys());
        });
        $this->assertSame([10], DB::table('table_memberships')->pluck('weight')->all());
        $sandbox->commit($owner);
        $this->assertSame([11, 20, 30], DB::table('table_memberships')->orderBy('weight')->pluck('weight')->all());
        $this->assertSame('table_memberships', TableMember::findOrFail(1)->roles()->getTable());
    }

    #[Test]
    public function syncDetachAndRollbackPreserveActiveAssignments(): void
    {
        $owner = $this->createUser();
        $sandbox = $this->sandbox();
        $sandbox->edit($owner, function (): void {
            $roles = TableMember::findOrFail(1)->roles();
            $roles->sync([1 => ['weight' => 15], 2 => ['weight' => 25]]);
            $roles->detach(1);
            $this->assertSame([2], $roles->get()->modelKeys());
        });
        $sandbox->rollback($owner);
        $this->assertSame([1], TableMember::findOrFail(1)->roles()->get()->modelKeys());
        $this->assertSame([10], DB::table('table_memberships')->pluck('weight')->all());
    }

    #[Test]
    public function readsEagerLoadsAndExistenceQueriesRespectReaderVisibility(): void
    {
        $owner = $this->createUser();
        $sandbox = $this->sandbox();
        $sandbox->edit($owner, function (): void {
            TableMember::findOrFail(1)->roles()->sync([2 => ['weight' => 20]]);
            TableRole::findOrFail(2)->update(['name' => 'draft editor']);
        });
        $sandbox->read($owner, function (): void {
            $member = TableMember::with('roles')->withCount('roles')
                ->whereHas('roles', fn ($query) => $query->where('name', 'draft editor'))->sole();
            $this->assertSame([2], $member->roles->modelKeys());
            $this->assertSame(1, $member->roles_count);
        });
        $sandbox->read($owner->getKey() + 1, function (): void {
            $this->assertSame([1], TableMember::findOrFail(1)->roles()->get()->modelKeys());
        });
        $sandbox->save($owner);
        $sandbox->read($owner->getKey() + 1, function (): void {
            $this->assertSame([2], TableMember::findOrFail(1)->roles()->get()->modelKeys());
        });
    }

    #[Test]
    public function hydratedParentsAndExplicitScopesKeepTheirSelectedLayer(): void
    {
        $sandbox = $this->sandbox();
        $active = TableMember::findOrFail(1);
        $draft = $sandbox->edit($this->createUser(), function () use ($active): TableMember {
            $this->assertSame('table_memberships', $active->roles()->getTable());
            $this->assertSame('table_roles', $active->roles()->getRelated()->getTable());
            $draft = TableMember::findOrFail(1);
            $draft->roles()->sync([2 => ['weight' => 20]]);
            $this->assertSame([1], TableMember::active()->findOrFail(1)->roles()->get()->modelKeys());

            return $draft;
        });
        $this->assertSame([2], $draft->roles()->get()->modelKeys());
        $this->assertSame([2], TableMember::sandbox()->findOrFail(1)->roles()->get()->modelKeys());
        TableMember::withSandbox(function (): void {
            $this->assertSame([2], TableMember::findOrFail(1)->roles()->get()->modelKeys());

            try {
                TableMember::withoutSandbox(function (): void {
                    $this->assertSame([1], TableMember::findOrFail(1)->roles()->get()->modelKeys());

                    throw new RuntimeException('restore context');
                });
            } catch (RuntimeException) {
                $this->assertSame([2], TableMember::findOrFail(1)->roles()->get()->modelKeys());
            }
        });
        $this->assertSame([1], $active->roles()->get()->modelKeys());
    }

    #[Test]
    public function failedEditsRollBackThePivotAndNewSandboxLock(): void
    {
        $sandbox = $this->sandbox();

        try {
            $sandbox->edit($this->createUser(), function (): void {
                TableMember::findOrFail(1)->roles()->attach(2, ['weight' => 20]);

                throw new RuntimeException('failed edit');
            });
            $this->fail('The edit must propagate its failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed edit', $exception->getMessage());
        }
        $this->assertSame([10], DB::table('table_memberships')->pluck('weight')->all());
        $this->assertSame(0, DB::table('table_memberships_sb')->count());
        $this->assertFalse(TableMember::isUsingSandbox());
        $this->assertTrue($sandbox->status() === null || $sandbox->status()->isFree());
    }

    #[Test]
    public function customPivotClassesKeepCastsAndEvents(): void
    {
        $created = 0;
        MembershipPivot::created(function () use (&$created): void {
            $created++;
        });
        $this->sandbox()->edit($this->createUser(), function (): void {
            $member = TableMember::findOrFail(1);
            $member->customRoles()->attach(2, ['weight' => '25']);
            $pivot = $member->customRoles()->whereKey(2)->sole()->pivot;
            $this->assertInstanceOf(MembershipPivot::class, $pivot);
            $this->assertSame(25, $pivot->weight);
            $this->assertSame('table_memberships_sb', $pivot->getTable());
            $member->roles()->using(MembershipPivot::class)->updateExistingPivot(1, ['weight' => 12]);
        });
        $this->assertSame(1, $created);
        $this->assertSame([10], DB::table('table_memberships')->pluck('weight')->all());
    }

    #[Test]
    public function selfRelationsKeepEagerLoadingAndExistenceAliases(): void
    {
        $this->sandbox()->edit($this->createUser(), function (): void {
            TableMember::findOrFail(1)->peers()->sync([2 => ['weight' => 20]]);
            $member = TableMember::with('peers')->withCount('peers')
                ->whereHas('peers', fn ($query) => $query->where('name', 'other'))->sole();
            $this->assertSame([2], $member->peers->modelKeys());
            $this->assertSame(1, $member->peers_count);
            TableMember::findOrFail(2)->peers()->attach(1, ['weight' => 30]);
            $this->assertSame([1], TableMember::whereHas(
                'peers.peers', fn ($query) => $query->where('name', 'member'),
            )->get()->modelKeys());
        });
        $this->assertSame([1], TableMember::findOrFail(1)->peers()->get()->modelKeys());
    }

    #[Test]
    public function anUnregisteredPivotKeepsItsOriginalTable(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->models(TableMember::class, TableRole::class);
        $sandbox->edit($this->createUser(), function (): void {
            $this->assertSame('table_memberships', TableMember::findOrFail(1)->roles()->getTable());
        });
        $this->assertSame(0, DB::table('table_memberships_sb')->count());
    }

    #[Test]
    public function customDraftNamesAndDuplicateRegistrationsAreSupported(): void
    {
        Schema::rename('table_memberships_sb', 'membership_draft');
        $sandbox = app(Sandbox::class);
        $sandbox->models(TableMember::class, TableRole::class);
        $definition = new SandboxTable('table_memberships', ['member_id', 'role_id'], 'membership_draft');
        $sandbox->tables($definition, clone $definition);
        $owner = $this->createUser();
        $sandbox->edit($owner, function (): void {
            $roles = TableMember::findOrFail(1)->roles();
            $this->assertSame('membership_draft', $roles->getTable());
            $roles->sync([2 => ['weight' => 25]]);
        });
        $sandbox->commit($owner);
        $this->assertSame([25], DB::table('table_memberships')->pluck('weight')->all());
        $this->assertSame([TableMember::class, TableRole::class], app(SandboxModelRegistry::class)->all());
    }

    #[Test]
    public function tablesCanParticipateWithoutAnyRegisteredModels(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->tables(new SandboxTable('table_memberships', ['member_id', 'role_id']));
        $owner = $this->createUser();
        $sandbox->edit($owner, function (): void {
            DB::table('table_memberships_sb')->update(['weight' => 33]);
        });
        $sandbox->commit($owner);
        $this->assertSame([33], DB::table('table_memberships')->pluck('weight')->all());
    }

    #[Test]
    public function conflictingDefinitionsAreRejectedBeforeUse(): void
    {
        $sandbox = $this->sandbox();
        $this->expectException(InvalidArgumentException::class);
        $sandbox->tables(new SandboxTable('table_memberships', ['member_id']));
    }

    #[Test]
    public function aTableCannotAlsoBeRegisteredAsAModel(): void
    {
        $sandbox = $this->sandbox();
        $this->expectException(InvalidArgumentException::class);
        $sandbox->tables(new SandboxTable('table_members', ['id']));
    }

    #[Test]
    public function tableRegistrationAlsoRejectsAConflictingLaterModel(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->tables(new SandboxTable('table_members', ['id']));
        $this->expectException(InvalidArgumentException::class);
        $sandbox->models(TableMember::class);
    }

    #[Test]
    public function aDifferentContextConnectionIsRejectedBeforeTheCallback(): void
    {
        $this->sandbox();
        config(['database.connections.other' => config('database.connections.testing')]);

        try {
            app(SandboxModelRegistry::class)->usingTables(true, function (): void {
                $this->fail('A mismatched connection must not run user code.');
            }, DB::connection('other'));
            $this->fail('The connection mismatch must be rejected.');
        } catch (SandboxException $exception) {
            $this->assertSame(SandboxException::CODE_MODEL_NOT_REGISTERED, $exception->getCode());
        }
        $this->assertFalse(TableMember::isUsingSandbox());
        $this->assertSame('table_memberships', TableMember::findOrFail(1)->roles()->getTable());
    }

    #[Test]
    public function synchronizationRetainsTheRegisteredConnectionWhenTheDefaultChanges(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->tables(new SandboxTable('table_memberships', ['member_id', 'role_id']));
        $connection = DB::connection();
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('unconfigured');

        try {
            app(SandboxModelRegistry::class)->resetSandbox();
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->assertSame([10], $connection->table('table_memberships_sb')->pluck('weight')->all());
    }
}

class TableMember extends Model
{
    use HasSandbox;

    protected $table = 'table_members';
    protected $guarded = [];
    public $timestamps = false;

    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return null;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(TableRole::class, 'table_memberships', 'member_id', 'role_id')->withPivot('weight');
    }

    public function customRoles(): BelongsToMany
    {
        return $this->belongsToMany(TableRole::class, MembershipPivot::class, 'member_id', 'role_id')->withPivot('weight');
    }

    public function peers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'table_memberships', 'member_id', 'role_id')->withPivot('weight');
    }
}

class TableRole extends Model
{
    use HasSandbox;

    protected $table = 'table_roles';
    protected $guarded = [];
    public $timestamps = false;

    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return null;
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(TableMember::class, 'table_memberships', 'role_id', 'member_id')->withPivot('weight');
    }
}

class MembershipPivot extends Pivot
{
    protected $table = 'table_memberships';
    public $timestamps = false;

    protected function casts(): array
    {
        return ['weight' => 'integer'];
    }
}
