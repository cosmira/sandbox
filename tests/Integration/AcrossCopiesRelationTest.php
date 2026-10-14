<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Eloquent\Context;
use Cosmira\Sandbox\Eloquent\TableContext;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class AcrossCopiesRelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['', '_sb'] as $suffix) {
            foreach (['copy_members', 'copy_roles'] as $name) {
                Schema::create($name.$suffix, function (Blueprint $table): void {
                    $table->string('id')->primary();
                    $table->string('code')->unique();
                });
            }

            foreach (['copy_memberships', 'copy_code_memberships'] as $name) {
                Schema::create($name.$suffix, function (Blueprint $table): void {
                    $table->string('member_key');
                    $table->string('role_key');
                    $table->integer('weight')->nullable();
                    $table->string('note')->nullable();
                    $table->timestamps();
                    $table->primary(['member_key', 'role_key']);
                });
            }

            Schema::create('copy_loose_memberships'.$suffix, function (Blueprint $table): void {
                $table->id();
                $table->string('member_key');
                $table->string('role_key');
                $table->integer('weight')->nullable();
            });

            Schema::create('copy_unique_memberships'.$suffix, function (Blueprint $table): void {
                $table->string('member_key');
                $table->string('role_key');
                $table->string('note')->unique();
                $table->primary(['member_key', 'role_key']);
            });

            DB::table('copy_members'.$suffix)->insert([
                ['id' => 'member', 'code' => 'member-code'],
                ['id' => 'other', 'code' => 'other-code'],
            ]);
            DB::table('copy_roles'.$suffix)->insert([
                ['id' => 'old', 'code' => 'old-code'],
                ['id' => 'new', 'code' => 'new-code'],
                ['id' => '1', 'code' => 'one-code'],
            ]);
            DB::table('copy_memberships'.$suffix)->insert([
                ['member_key' => 'member', 'role_key' => 'old', 'weight' => 10],
                ['member_key' => 'other', 'role_key' => 'old', 'weight' => 30],
            ]);
        }

        app(Sandbox::class)->models(CopyMember::class, CopyRole::class);
        app(Sandbox::class)->tables(
            new SandboxTable('copy_memberships', ['member_key', 'role_key']),
            new SandboxTable('copy_code_memberships', ['member_key', 'role_key']),
            new SandboxTable('copy_loose_memberships', ['id']),
            new SandboxTable('copy_unique_memberships', ['member_key', 'role_key']),
        );
    }

    #[Test]
    public function activeDeltaPreservesUnrelatedDraftAssignmentsAndOtherParents(): void
    {
        DB::table('copy_roles_sb')->insert(['id' => 'draft', 'code' => 'draft-code']);
        DB::table('copy_memberships_sb')->insert(['member_key' => 'member', 'role_key' => 'draft']);
        $member = CopyMember::findOrFail('member');
        $affected = $member->roles()->syncAcrossCopies(['new' => ['weight' => 20]]);

        $this->assertSame(['copy_members', 'copy_members_sb'], array_map(fn (Model $copy) => $copy->getTable(), $affected));
        $this->assertSame(['new'], $member->roles()->get()->modelKeys());
        $this->assertSame(['draft', 'new'], CopyMember::sandbox()->findOrFail('member')->roles()->orderBy('id')->get()->modelKeys());
        $this->assertSame('copy_members', $member->getTable());
        $this->assertFalse(CopyMember::isUsingSandbox());
        $this->assertFalse(CopyRole::isUsingSandbox());
        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseHas('copy_memberships'.$suffix, ['member_key' => 'member', 'role_key' => 'new', 'weight' => 20]);
            $this->assertDatabaseHas('copy_memberships'.$suffix, ['member_key' => 'other', 'role_key' => 'old', 'weight' => 30]);
        }
    }

    #[Test]
    public function draftDeltaPreservesUnrelatedActiveAssignments(): void
    {
        DB::table('copy_roles')->insert(['id' => 'active', 'code' => 'active-code']);
        DB::table('copy_memberships')->insert(['member_key' => 'member', 'role_key' => 'active']);
        $member = CopyMember::sandbox()->findOrFail('member');
        $member->roles()->syncAcrossCopies(['new' => ['note' => 'review']]);

        $this->assertSame(['active', 'new'], CopyMember::active()->findOrFail('member')->roles()->orderBy('id')->get()->modelKeys());
        $this->assertSame(['new'], $member->roles()->get()->modelKeys());
        $this->assertSame('copy_members_sb', $member->getTable());
        $this->assertFalse(CopyMember::isUsingSandbox());
    }

    #[Test]
    public function emptyPayloadClearsOnlyTheSelectedSet(): void
    {
        DB::table('copy_roles_sb')->insert(['id' => 'draft', 'code' => 'draft-code']);
        DB::table('copy_memberships_sb')->insert(['member_key' => 'member', 'role_key' => 'draft']);
        CopyMember::findOrFail('member')->roles()->syncAcrossCopies([]);

        $this->assertSame([], CopyMember::findOrFail('member')->roles()->get()->modelKeys());
        $this->assertSame(['draft'], CopyMember::sandbox()->findOrFail('member')->roles()->get()->modelKeys());
    }

    #[Test]
    public function numericStringKeysRemainDistinctAndMissingRelatedCopiesAreSkipped(): void
    {
        DB::table('copy_roles_sb')->insert(['id' => '01', 'code' => 'zero-one-code']);
        CopyMember::sandbox()->findOrFail('member')->roles()->syncAcrossCopies([
            '1'       => ['weight' => 1],
            '01'      => ['weight' => 2],
            'missing' => ['weight' => 3],
        ]);

        $this->assertSame(['1'], CopyMember::active()->findOrFail('member')->roles()->get()->modelKeys());
        $this->assertSame(['01', '1'], CopyMember::sandbox()->findOrFail('member')->roles()->orderBy('id')->get()->modelKeys());
        CopyMember::sandbox()->findOrFail('member')->roles()->syncAcrossCopies(['1', '01']);

        $this->assertSame(['1'], CopyMember::active()->findOrFail('member')->roles()->get()->modelKeys());
        $this->assertSame(['01', '1'], CopyMember::sandbox()->findOrFail('member')->roles()->orderBy('id')->get()->modelKeys());
        $this->assertDatabaseHas('copy_memberships_sb', ['role_key' => '01', 'weight' => 2]);
    }

    #[Test]
    public function missingParentsSuppressInsertsButRetainRemovalAndResultIdentity(): void
    {
        DB::table('copy_members_sb')->where('id', 'member')->delete();
        $affected = CopyMember::findOrFail('member')->roles()->syncAcrossCopies(['new' => ['weight' => 20]]);

        $this->assertCount(2, $affected);
        $this->assertTrue($affected[0]->exists);
        $this->assertFalse($affected[1]->exists);
        $this->assertSame('member', $affected[1]->getKey());
        $this->assertSame('copy_members_sb', $affected[1]->getTable());
        $this->assertDatabaseMissing('copy_memberships_sb', ['member_key' => 'member']);
        $this->assertDatabaseMissing('copy_members_sb', ['id' => 'member']);
    }

    #[Test]
    public function missingActiveParentCanBeUpdatedFromTheDraft(): void
    {
        DB::table('copy_members')->where('id', 'member')->delete();
        $affected = CopyMember::sandbox()->findOrFail('member')->roles()->syncAcrossCopies(['new']);

        $this->assertFalse($affected[0]->exists);
        $this->assertTrue($affected[1]->exists);
        $this->assertDatabaseMissing('copy_memberships', ['member_key' => 'member']);
        $this->assertDatabaseHas('copy_memberships_sb', ['member_key' => 'member', 'role_key' => 'new']);
    }

    #[Test]
    public function alternativeRelationKeysAreUsedInsteadOfPrimaryKeys(): void
    {
        CopyMember::findOrFail('member')->codedRoles()->syncAcrossCopies(['new-code' => ['weight' => 12]]);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseHas('copy_code_memberships'.$suffix, [
                'member_key' => 'member-code', 'role_key' => 'new-code', 'weight' => 12,
            ]);
        }
    }

    #[Test]
    public function customDraftPivotNamesAreResolvedFromRegistration(): void
    {
        Schema::rename('copy_memberships_sb', 'copy_membership_draft');
        $this->app->forgetInstance(Sandbox::class);
        $this->app->forgetInstance(SandboxModelRegistry::class);
        $this->app->forgetInstance(TableContext::class);
        Context::clearResolvedInstances();
        app(Sandbox::class)->models(CopyMember::class, CopyRole::class);
        app(Sandbox::class)->tables(new SandboxTable(
            'copy_memberships', ['member_key', 'role_key'], sandboxTable: 'copy_membership_draft',
        ));
        CopyMember::findOrFail('member')->roles()->syncAcrossCopies(['new' => ['weight' => 17]]);

        $this->assertDatabaseHas('copy_membership_draft', ['member_key' => 'member', 'role_key' => 'new', 'weight' => 17]);
    }

    #[Test]
    public function customPivotCastsAndEventsArePreservedWithoutParentTouches(): void
    {
        $created = [];
        $updated = 0;
        CopyMembership::created(function (CopyMembership $pivot) use (&$created): void {
            $created[] = [$pivot->getTable(), $pivot->weight];
        });
        CopyMember::updated(function () use (&$updated): void {
            $updated++;
        });
        CopyMember::findOrFail('member')->customRoles()->syncAcrossCopies([
            'old' => ['weight' => '11'], 'new' => ['weight' => '20'],
        ]);

        $this->assertSame([['copy_memberships', 20], ['copy_memberships_sb', 20]], $created);
        $this->assertSame(0, $updated);
        $this->assertDatabaseHas('copy_memberships', ['member_key' => 'member', 'role_key' => 'old', 'weight' => 11]);
        $this->assertDatabaseHas('copy_memberships_sb', ['member_key' => 'member', 'role_key' => 'old', 'weight' => 11]);
    }

    #[Test]
    public function failureInTheSecondCopyRollsBackBothCopies(): void
    {
        CopyMembership::created(function (CopyMembership $pivot): void {
            if ($pivot->getTable() === 'copy_memberships_sb') {
                throw new RuntimeException('second copy failed');
            }
        });

        try {
            CopyMember::findOrFail('member')->customRoles()->syncAcrossCopies(['new' => ['weight' => 20]]);
            $this->fail('The write must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('second copy failed', $exception->getMessage());
        }

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseMissing('copy_memberships'.$suffix, ['role_key' => 'new']);
            $this->assertDatabaseHas('copy_memberships'.$suffix, ['member_key' => 'member', 'role_key' => 'old', 'weight' => 10]);
        }
    }

    #[Test]
    public function timestampsPreserveCreationDatesOnRepeatedAssignments(): void
    {
        $this->freezeTime();
        $member = CopyMember::findOrFail('member');
        $member->timedRoles()->syncAcrossCopies(['new' => ['note' => 'first']]);
        $created = now()->toDateTimeString();
        $this->travel(1)->seconds();
        $member->timedRoles()->syncAcrossCopies(['new' => ['note' => 'second']]);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseHas('copy_memberships'.$suffix, [
                'member_key' => 'member', 'role_key' => 'new', 'note' => 'second',
                'created_at' => $created, 'updated_at' => now()->toDateTimeString(),
            ]);
        }
    }

    #[Test]
    public function heterogeneousAndIdOnlyPayloadsUseNormalPivotSemantics(): void
    {
        $member = CopyMember::findOrFail('member');
        $member->roles()->syncAcrossCopies(['old' => ['weight' => 15], 'new' => ['note' => 'different columns']]);
        $member->roles()->syncAcrossCopies(['old', 'new']);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseHas('copy_memberships'.$suffix, ['role_key' => 'old', 'weight' => 15]);
            $this->assertDatabaseHas('copy_memberships'.$suffix, ['role_key' => 'new', 'note' => 'different columns']);
        }
    }

    #[Test]
    public function emptyDeltaReturnsNoAffectedCopies(): void
    {
        $member = CopyMember::findOrFail('other');
        $member->roles()->detach();
        $this->assertSame([], $member->roles()->syncAcrossCopies([]));
    }

    #[Test]
    public function sandboxOwnershipAndOuterTransactionRemainUnderHostControl(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->open($this->createUser());
        $status = (array) DB::table('sandbox_status')->first();

        DB::beginTransaction();
        CopyMember::findOrFail('member')->roles()->syncAcrossCopies(['new' => ['weight' => 20]]);
        DB::rollBack();

        $this->assertSame($status, (array) DB::table('sandbox_status')->first());
        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseMissing('copy_memberships'.$suffix, ['role_key' => 'new']);
        }
    }

    #[Test]
    public function runtimeFiltersAreRejectedBeforeEitherCopyChanges(): void
    {
        try {
            CopyMember::findOrFail('member')->roles()->wherePivot('weight', 10)->syncAcrossCopies([]);
            $this->fail('A runtime pivot filter must not be ignored.');
        } catch (InvalidArgumentException) {
            $this->assertSame(4, DB::table('copy_memberships')->count() + DB::table('copy_memberships_sb')->count());
        }
    }

    #[Test]
    public function ordinaryUniformAssignmentsUseOneBatchPerCopy(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (preg_match('/^(?:insert|merge)\b/i', $query->sql) === 1
                && str_contains($query->sql, 'copy_memberships')) {
                $queries[] = $query->sql;
            }
        });
        CopyMember::findOrFail('member')->roles()->syncAcrossCopies([
            'old' => ['weight' => 11], 'new' => ['weight' => 20], '1' => ['weight' => 30],
        ]);

        $this->assertCount(2, $queries);
    }

    #[Test]
    public function unregisteredPivotsAreRejectedBeforeWrites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CopyMember::findOrFail('member')->unregisteredRoles()->syncAcrossCopies([]);
    }

    #[Test]
    public function declaredPivotFiltersAreRejectedBeforeWrites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CopyMember::findOrFail('member')->filteredRoles()->syncAcrossCopies([]);
    }

    #[Test]
    public function mismatchedConnectionsAreRejectedBeforeWrites(): void
    {
        config(['database.connections.other' => config('database.connections.testing')]);
        $this->expectException(InvalidArgumentException::class);
        $relation = CopyMember::findOrFail('member')->roles();
        $relation->getRelated()->setConnection('other');
        $relation->syncAcrossCopies([]);
    }

    #[Test]
    public function repeatedValuesStillReturnAffectedCopies(): void
    {
        $before = (array) DB::table('sandbox_status')->first();
        $member = CopyMember::findOrFail('member');
        $this->assertCount(2, $member->roles()->syncAcrossCopies(['old' => ['weight' => 10]]));
        $this->assertCount(2, $member->roles()->syncAcrossCopies(['old' => ['weight' => 10]]));
        $this->assertSame($before, (array) DB::table('sandbox_status')->first());
    }

    #[Test]
    public function removalDeltaAffectsAnAlreadyEmptyOtherCopy(): void
    {
        DB::table('copy_memberships_sb')->where('member_key', 'member')->delete();
        $this->assertCount(2, CopyMember::findOrFail('member')->roles()->syncAcrossCopies([]));
    }

    #[Test]
    public function nonScalarIdsAreRejectedBeforeWrites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CopyMember::findOrFail('member')->roles()->syncAcrossCopies([new \stdClass]);
    }

    #[Test]
    public function pivotBetweenFiltersAreRejectedBeforeWrites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CopyMember::findOrFail('member')->roles()->wherePivotBetween('weight', [1, 30])->syncAcrossCopies([]);
    }

    #[Test]
    public function relatedQueryPredicatesAreRejectedBeforeWrites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CopyMember::findOrFail('member')->roles()->where('copy_roles.code', 'old-code')->syncAcrossCopies([]);
    }

    #[Test]
    public function payloadFactoryRunsOnceAfterBothParentLocks(): void
    {
        $member = CopyMember::findOrFail('member');
        $locked = [];
        $calls = 0;
        CopyMember::retrieved(function (CopyMember $copy) use (&$locked): void {
            $locked[] = $copy->getTable();
        });
        $member->roles()->syncAcrossCopies(function () use (&$calls, &$locked): array {
            $calls++;
            $this->assertSame(['copy_members', 'copy_members_sb'], $locked);
            $this->assertGreaterThan(0, DB::transactionLevel());

            return ['new' => ['weight' => 20]];
        });

        $this->assertSame(1, $calls);
    }

    #[Test]
    public function sourceProjectionIsRejectedBeforeWrites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CopyMember::findOrFail('member')->roles()->select('copy_roles.code')->syncAcrossCopies([]);
    }

    #[Test]
    public function sourceLimitIsRejectedBeforeWrites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CopyMember::findOrFail('member')->roles()->limit(1)->syncAcrossCopies([]);
    }

    #[Test]
    public function oneRelationInstanceCanBeReusedWithoutQueryMutation(): void
    {
        $roles = CopyMember::findOrFail('member')->roles();
        $sql = $roles->toSql();
        $roles->syncAcrossCopies(['new' => ['weight' => 20]]);
        $roles->syncAcrossCopies(['old' => ['weight' => 11]]);

        $this->assertSame($sql, $roles->toSql());
        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseMissing('copy_memberships'.$suffix, ['role_key' => 'new']);
            $this->assertDatabaseHas('copy_memberships'.$suffix, ['member_key' => 'member', 'role_key' => 'old', 'weight' => 11]);
        }
    }

    #[Test]
    public function pivotsWithoutAUniqueRelationPairDoNotDuplicateAssignments(): void
    {
        $roles = CopyMember::findOrFail('member')->looseRoles();
        $roles->syncAcrossCopies(['new' => ['weight' => 20]]);
        $roles->syncAcrossCopies(['new' => ['weight' => 25]]);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseCount('copy_loose_memberships'.$suffix, 1);
            $this->assertDatabaseHas('copy_loose_memberships'.$suffix, [
                'member_key' => 'member', 'role_key' => 'new', 'weight' => 25,
            ]);
        }
    }

    #[Test]
    #[DataProvider('unsupportedIds')]
    public function unsupportedScalarIdsCannotBeCoercedIntoRealAssignments(mixed $id): void
    {
        $before = DB::table('copy_memberships')->get()->all();

        try {
            CopyMember::findOrFail('member')->roles()->syncAcrossCopies([$id]);
            $this->fail('Unsupported IDs must be rejected before normalization.');
        } catch (InvalidArgumentException) {
            $this->assertEquals($before, DB::table('copy_memberships')->get()->all());
        }
    }

    public static function unsupportedIds(): iterable
    {
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'null' => [null];
        yield 'float' => [1.9];
    }

    #[Test]
    public function otherUniqueConstraintsCannotRedirectAnUpsertToAnotherParent(): void
    {
        CopyMember::findOrFail('other')->uniqueRoles()->attach('old', ['note' => 'taken']);

        try {
            CopyMember::findOrFail('member')->uniqueRoles()->syncAcrossCopies(['new' => ['note' => 'taken']]);
            $this->fail('The unique constraint must reject this assignment.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('copy_unique_memberships', ['member_key' => 'member']);
            $this->assertDatabaseMissing('copy_unique_memberships_sb', ['member_key' => 'member']);
            $this->assertDatabaseHas('copy_unique_memberships', [
                'member_key' => 'other', 'role_key' => 'old', 'note' => 'taken',
            ]);
        }
    }

    #[Test]
    public function enumCastsCannotChangeTheCurrentRelationIdentity(): void
    {
        foreach (['', '_sb'] as $suffix) {
            DB::table('copy_code_memberships'.$suffix)->insert([
                'member_key' => 'member-code', 'role_key' => 'old-code', 'weight' => 10,
            ]);
        }

        $roles = CopyMember::findOrFail('member')->codedRoles();
        $roles->getRelated()->mergeCasts(['code' => CopyRoleCode::class]);
        $roles->syncAcrossCopies(['new-code' => ['weight' => 20]]);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseMissing('copy_code_memberships'.$suffix, ['role_key' => 'old-code']);
            $this->assertDatabaseHas('copy_code_memberships'.$suffix, [
                'member_key' => 'member-code', 'role_key' => 'new-code', 'weight' => 20,
            ]);
        }
    }

    #[Test]
    public function enumCastsCannotChangeTheRequestedRelationIdentity(): void
    {
        $roles = CopyMember::findOrFail('member')->codedRoles();
        $roles->getRelated()->mergeCasts(['code' => CopyRoleCode::class]);
        $roles->syncAcrossCopies(['new-code' => ['weight' => 20]]);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseHas('copy_code_memberships'.$suffix, [
                'member_key' => 'member-code', 'role_key' => 'new-code', 'weight' => 20,
            ]);
        }
    }

    #[Test]
    public function accessorsCannotChangeTheDatabaseRelationIdentity(): void
    {
        foreach (['', '_sb'] as $suffix) {
            DB::table('copy_code_memberships'.$suffix)->insert([
                'member_key' => 'member-code', 'role_key' => 'old-code', 'weight' => 10,
            ]);
        }

        CopyMember::findOrFail('member')->decoratedCodedRoles()->syncAcrossCopies(['new-code' => ['weight' => 20]]);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseMissing('copy_code_memberships'.$suffix, ['role_key' => 'old-code']);
            $this->assertDatabaseHas('copy_code_memberships'.$suffix, [
                'member_key' => 'member-code', 'role_key' => 'new-code', 'weight' => 20,
            ]);
        }
    }

    #[Test]
    public function rawIdentityQueriesRetainRelatedGlobalScopes(): void
    {
        CopyMember::findOrFail('member')->scopedRoles()->syncAcrossCopies(['new' => ['weight' => 20]]);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseMissing('copy_memberships'.$suffix, ['member_key' => 'member']);
            $this->assertDatabaseHas('copy_memberships'.$suffix, [
                'member_key' => 'other', 'role_key' => 'old', 'weight' => 30,
            ]);
        }
        CopyMember::findOrFail('member')->scopedRoles()->syncAcrossCopies(['1' => ['weight' => 20]]);

        foreach (['', '_sb'] as $suffix) {
            $this->assertDatabaseHas('copy_memberships'.$suffix, [
                'member_key' => 'member', 'role_key' => '1', 'weight' => 20,
            ]);
        }
    }

    #[Test]
    public function pivotIdentityOverridesAreRejectedBeforeEitherCopyChanges(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CopyMember::findOrFail('member')->roles()->syncAcrossCopies(['new' => ['member_key' => 'other']]);
    }
}

class CopyMember extends Model
{
    use HasSandbox;

    protected $table = 'copy_members';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(CopyRole::class, 'copy_memberships', 'member_key', 'role_key')->withPivot('weight', 'note');
    }

    public function customRoles(): BelongsToMany
    {
        return $this->roles()->using(CopyMembership::class);
    }

    public function timedRoles(): BelongsToMany
    {
        return $this->roles()->withTimestamps();
    }

    public function unregisteredRoles(): BelongsToMany
    {
        return $this->belongsToMany(CopyRole::class, 'unregistered_memberships', 'member_key', 'role_key');
    }

    public function filteredRoles(): BelongsToMany
    {
        return $this->belongsToMany(CopyRole::class, 'copy_memberships', 'member_key', 'role_key')->wherePivot('weight', 10);
    }

    public function looseRoles(): BelongsToMany
    {
        return $this->belongsToMany(CopyRole::class, 'copy_loose_memberships', 'member_key', 'role_key');
    }

    public function uniqueRoles(): BelongsToMany
    {
        return $this->belongsToMany(CopyRole::class, 'copy_unique_memberships', 'member_key', 'role_key');
    }

    public function decoratedCodedRoles(): BelongsToMany
    {
        return $this->belongsToMany(DecoratedCopyRole::class, 'copy_code_memberships', 'member_key', 'role_key', 'code', 'code');
    }

    public function scopedRoles(): BelongsToMany
    {
        return $this->belongsToMany(ScopedCopyRole::class, 'copy_memberships', 'member_key', 'role_key');
    }

    public function codedRoles(): BelongsToMany
    {
        return $this->belongsToMany(CopyRole::class, 'copy_code_memberships', 'member_key', 'role_key', 'code', 'code');
    }
}

class CopyRole extends Model
{
    use HasSandbox;

    protected $table = 'copy_roles';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
}

class CopyMembership extends Pivot
{
    protected $table = 'copy_memberships';
    public $timestamps = false;

    protected function casts(): array
    {
        return ['weight' => 'integer'];
    }
}

enum CopyRoleCode: string
{
    case Old = 'old-code';
    case New = 'new-code';
}

class DecoratedCopyRole extends CopyRole
{
    protected function code(): Attribute
    {
        return Attribute::get(static fn (string $value): string => strtoupper($value));
    }
}

class ScopedCopyRole extends CopyRole
{
    protected static function booted(): void
    {
        static::addGlobalScope('exclude-new', static function (Builder $query): void {
            $query->select($query->getModel()->qualifyColumn('code'));
            $query->where($query->getModel()->qualifyColumn('id'), '!=', 'new');
        });
    }
}
