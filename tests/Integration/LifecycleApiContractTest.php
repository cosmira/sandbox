<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Backends\EloquentSandboxBackend;
use Cosmira\Sandbox\Contracts\SandboxBackend;
use Cosmira\Sandbox\Enums\SandboxStatus as Status;
use Cosmira\Sandbox\Events;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Facades\Sandbox as SandboxFacade;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Support\SandboxStatusLocker;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class LifecycleApiContractTest extends TestCase
{
    public static function forbiddenMutations(): iterable
    {
        foreach (['commit', 'save', 'rollback', 'reset'] as $operation) {
            yield $operation.' by non-owner' => [$operation, Status::Locked, 2];
            yield $operation.' when free' => [$operation, Status::Free, 1];
            yield $operation.' when saved' => [$operation, Status::Saved, 1];
        }
    }

    #[Test]
    #[DataProvider('forbiddenMutations')]
    public function mutationsRequireAnOpenDraftOwnedByTheActor(string $operation, Status $status, int $actor): void
    {
        SandboxStatus::query()->update(['status' => $status, 'user_id' => 1]);
        $before = SandboxStatus::firstOrFail()->getAttributes();
        ApiResetModel::$resets = 0;
        Event::fake([
            Events\SandboxCommitting::class,
            Events\SandboxCommitted::class,
            Events\SandboxResetting::class,
            Events\SandboxRollingBack::class,
            Events\SandboxRolledBack::class,
            Events\SandboxSaved::class,
            Events\SandboxOpened::class,
        ]);

        try {
            $operation === 'reset'
                ? SandboxFacade::for($actor)->reset(ApiResetModel::class)
                : SandboxFacade::for($actor)->{$operation}();
            $this->fail('Mutation was permitted without an owned open draft.');
        } catch (SandboxException $exception) {
            $this->assertSame(
                $status === Status::Locked ? SandboxException::CODE_SANDBOX_LOCKED : SandboxException::CODE_SANDBOX_FREE,
                $exception->getCode(),
            );
        }

        $this->assertSame($before, SandboxStatus::firstOrFail()->getAttributes());
        $this->assertSame(0, ApiResetModel::$resets);
        Event::assertNothingDispatched();
    }

    #[Test]
    public function savedDraftMustBeReopenedBeforeTheNextMutation(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->open(1);
        $sandbox->save(1);
        $sandbox->open(2);
        $sandbox->rollback(2);

        $this->assertTrue($sandbox->status()->isFree());
        $this->assertSame(2, $sandbox->status()->change_id);
    }

    #[Test]
    public function backendDoesNotForceTakeoverByDefault(): void
    {
        $backend = app(SandboxBackend::class);
        $backend->open(1);
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_SANDBOX_LOCKED);
        $backend->open(2);
    }

    #[Test]
    public function resumingSavedDraftClearsLastOperationWithoutResetEvent(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->open(1);
        $sandbox->save(1);
        Event::fake([Events\SandboxResetting::class]);
        $sandbox->open(2);
        $this->assertNull($sandbox->status()->last_operation);
        $this->assertTrue($sandbox->status()->isLockedBy(2));
        Event::assertNotDispatched(Events\SandboxResetting::class);
    }

    #[Test]
    public function statusLockerRejectsCallsOutsideATransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Sandbox status locking requires a transaction.');
        (new SandboxStatusLocker())->query(new SandboxStatus());
    }

    #[Test]
    public function statusLockIsAcquiredBeforeReadingWithoutChangingTheStatus(): void
    {
        $before = SandboxStatus::firstOrFail()->getAttributes();
        $connection = DB::connection();
        $connection->enableQueryLog();

        try {
            $status = $connection->transaction(fn () => (new SandboxStatusLocker())
                ->query(new SandboxStatus())->firstOrFail());
            $queries = $connection->getQueryLog();
            $this->assertSame($before, $status->getAttributes());
            if ($connection->getDriverName() === 'sqlite') {
                $this->assertStringStartsWith('update ', strtolower($queries[0]['query']));
                $this->assertStringStartsWith('select ', strtolower($queries[1]['query']));
            } else {
                $this->assertStringContainsString('for update', strtolower($queries[0]['query']));
            }
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    #[Test]
    public function authorizedForceOpenRecoveryMustPrecedeForeignRollback(): void
    {
        $sandbox = app(Sandbox::class);
        $sandbox->open(1);
        $sandbox->open(2, force: true);
        $sandbox->rollback(2);

        $this->assertTrue($sandbox->status()->isFree());
        $this->assertTrue($sandbox->status()->isForUser(2));
    }

    #[Test]
    public function resetApisDelegateTheActorAndModelToTheBackend(): void
    {
        $user = new ApiResetModel();
        $user->setAttribute('id', 7);
        $model = new ApiResetModel();
        $backend = $this->createMock(SandboxBackend::class);
        $backend->expects($this->exactly(4))->method('reset')->with(7, $model);
        $sandbox = new Sandbox(backend: $backend);
        $this->app->instance(Sandbox::class, $sandbox);
        SandboxFacade::clearResolvedInstance(Sandbox::class);

        SandboxFacade::reset($user, $model);
        SandboxFacade::resetSandboxData($user, $model);
        SandboxFacade::for(7)->reset($model);
        SandboxFacade::for(7)->apply($model);
    }

    #[Test]
    public function lifecycleApisDelegateToTheBackendWithoutUpdaterOptions(): void
    {
        $backend = $this->createMock(SandboxBackend::class);
        $backend->expects($this->once())->method('open')->with(7, true, 'Recovery');
        $backend->expects($this->once())->method('commit')->with(7, 'Commit');
        $backend->expects($this->once())->method('save')->with(7, 'Save');
        $backend->expects($this->once())->method('rollback')->with(7, 'Rollback');
        $sandbox = new Sandbox(backend: $backend);

        $sandbox->open(7, true, 'Recovery');
        $sandbox->commit(7, 'Commit');
        $sandbox->save(7, 'Save');
        $sandbox->rollback(7, 'Rollback');
    }

    #[Test]
    public function backendCanForbidResetWithoutExecutingModelSynchronization(): void
    {
        ApiResetModel::$resets = 0;
        $backend = $this->createMock(SandboxBackend::class);
        $backend->expects($this->once())->method('reset')
            ->willThrowException(new SandboxException('Use the host lifecycle.'));

        try {
            (new Sandbox(backend: $backend))->reset(7, ApiResetModel::class);
            $this->fail('The backend rejection was ignored.');
        } catch (SandboxException $exception) {
            $this->assertSame('Use the host lifecycle.', $exception->getMessage());
        }

        $this->assertSame(0, ApiResetModel::$resets);
    }

    #[Test]
    public function editUsesTheBackendConnectionTransactionAndRollsBackOnFailure(): void
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $backend = $this->createMock(SandboxBackend::class);
        $backend->method('connection')->willReturn($connection);
        $backend->expects($this->once())->method('open')->with(7, false, null)
            ->willReturnCallback(function () use ($connection, $level): void {
                $this->assertSame($level + 1, $connection->transactionLevel());
                $connection->table('sandbox_status')->update(['note' => 'Uncommitted']);
            });

        try {
            (new Sandbox(backend: $backend))->edit(7, function () use ($connection, $level): never {
                $this->assertSame($level + 1, $connection->transactionLevel());

                throw new RuntimeException('Reject edit.');
            });
            $this->fail('The callback failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Reject edit.', $exception->getMessage());
        }

        $this->assertSame($level, $connection->transactionLevel());
        $this->assertNull(SandboxStatus::firstOrFail()->note);
    }

    #[Test]
    public function guestReadUsesActiveDataAndOneStatusSnapshot(): void
    {
        $backend = $this->createMock(SandboxBackend::class);
        $backend->expects($this->once())->method('status')->willReturn(
            new SandboxStatus(['status' => Status::Saved, 'user_id' => 1]),
        );

        $result = (new Sandbox(backend: $backend))->read(null, fn (bool $draft): bool => $draft);

        $this->assertFalse($result);
    }

    #[Test]
    public function oneNonDefaultConnectionSupportsTheCompleteLifecycle(): void
    {
        $this->createSecondaryDatabase();
        $models = new SandboxModelRegistry();
        $models->register(SecondaryApiModel::class);
        $backend = new EloquentSandboxBackend(
            $models,
            statusModel: (new SandboxStatus())->setConnection('sandbox_secondary'),
        );
        $sandbox = new Sandbox($models, $backend);
        $connection = $backend->connection();
        $connection->table('api_items')->insert(['id' => 1, 'name' => 'Active']);

        $sandbox->edit(1, fn () => SecondaryApiModel::query()->whereKey(1)->update(['name' => 'Draft']));
        $sandbox->save(1);
        $sandbox->open(1);
        $sandbox->reset(1, SecondaryApiModel::class);
        $this->assertSame('Active', $connection->table('api_items_sb')->value('name'));
        $sandbox->edit(1, fn () => SecondaryApiModel::query()->whereKey(1)->update(['name' => 'Committed']));
        $sandbox->commit(1);
        $sandbox->open(1);
        $sandbox->rollback(1);

        $this->assertSame('Committed', $connection->table('api_items')->value('name'));
        $this->assertTrue($sandbox->status()->isFree());
        $this->assertSame(0, SandboxStatus::firstOrFail()->change_id);
    }

    public static function mixedConnectionOperations(): array
    {
        return [['open'], ['commit'], ['save'], ['rollback'], ['reset'], ['edit']];
    }

    #[Test]
    #[DataProvider('mixedConnectionOperations')]
    public function mixedConnectionsFailBeforeWriting(string $operation): void
    {
        $this->createSecondaryDatabase();
        $models = new SandboxModelRegistry();
        $models->register(SecondaryApiModel::class);
        $sandbox = new Sandbox($models, new EloquentSandboxBackend($models));
        SandboxStatus::query()->update(['status' => Status::Locked, 'user_id' => 1]);
        $before = SandboxStatus::firstOrFail()->getAttributes();
        DB::connection('sandbox_secondary')->table('api_items')->insert(['id' => 1, 'name' => 'Active']);
        DB::connection('sandbox_secondary')->table('api_items_sb')->insert(['id' => 1, 'name' => 'Draft']);

        try {
            match ($operation) {
                'reset' => $sandbox->reset(1, SecondaryApiModel::class),
                'edit'  => $sandbox->edit(1, fn () => $this->fail('Cross-connection callback was reached.')),
                default => $sandbox->{$operation}(1),
            };
            $this->fail('A mixed-connection mutation was permitted.');
        } catch (SandboxException $exception) {
            $this->assertSame(SandboxException::CODE_MODEL_NOT_REGISTERED, $exception->getCode());
        }

        $this->assertSame($before, SandboxStatus::firstOrFail()->getAttributes());
        $this->assertSame('Active', DB::connection('sandbox_secondary')->table('api_items')->value('name'));
        $this->assertSame('Draft', DB::connection('sandbox_secondary')->table('api_items_sb')->value('name'));
    }

    private function createSecondaryDatabase(): void
    {
        config(['database.connections.sandbox_secondary' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $connection = DB::connection('sandbox_secondary');
        $schema = $connection->getSchemaBuilder();
        $schema->create('sandbox_status', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('status');
            $table->integer('last_operation')->nullable();
            $table->string('user_id')->nullable();
            $table->string('note')->nullable();
            $table->dateTime('change_date');
            $table->integer('change_id')->default(0);
            $table->dateTime('send_date')->nullable();
        });
        $connection->table('sandbox_status')->insert(['id' => 1, 'status' => 0, 'change_date' => now()]);
        foreach (['api_items', 'api_items_sb'] as $name) {
            $schema->create($name, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('name');
            });
        }
        SecondaryApiModel::useActive();
    }
}

class ApiResetModel extends Model
{
    public static int $resets = 0;

    public static function resetSandbox(): void
    {
        self::$resets++;
    }
}

class SecondaryApiModel extends Model
{
    use HasSandbox;

    protected $connection = 'sandbox_secondary';

    protected $table = 'api_items';

    public $timestamps = false;

    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return null;
    }
}
