<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Backends\EloquentSandboxBackend;
use Cosmira\Sandbox\Events\SandboxChanged;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Cosmira\Sandbox\Tests\TestUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class SandboxTransactionTest extends TestCase
{
    #[Test]
    public function listenersSeeTheCompletedOperationBeforeItsResultIsReturned(): void
    {
        $sandbox = app(Sandbox::class);
        $user = $this->createUser();
        Event::listen(SandboxChanged::class, function (SandboxChanged $event) use ($sandbox, $user): void {
            $this->assertSame(TestUser::class, $event->model);
            $this->assertSame($sandbox->connection(), $event->connection);
            $this->assertSame('Changed', $user->fresh()->name);
            $user->update(['name' => 'Prepared']);
        });

        $result = $sandbox->connection()->transaction(function () use ($sandbox, $user): TestUser {
            $result = $sandbox->transaction(TestUser::class, function () use ($user): TestUser {
                $user->update(['name' => 'Changed']);

                return $user;
            });

            $this->assertSame('Prepared', $result->fresh()->name);

            return $result;
        });

        $this->assertSame($user, $result);
        $this->assertSame('Prepared', $user->fresh()->name);
    }

    #[Test]
    public function listenerFailureRollsBackTheOperationAndPreparation(): void
    {
        $sandbox = app(Sandbox::class);
        $user = $this->createUser();
        Event::listen(SandboxChanged::class, function () use ($user): void {
            $this->assertSame('Changed', $user->fresh()->name);
            $user->update(['name' => 'Partial preparation']);

            throw new RuntimeException('Preparation failed');
        });

        try {
            $sandbox->transaction(TestUser::class, fn () => $user->update(['name' => 'Changed']));
            $this->fail('Listener failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Preparation failed', $exception->getMessage());
        }

        $this->assertSame('Test User', $user->fresh()->name);
    }

    #[Test]
    public function failedOperationsDoNotDispatchCompletion(): void
    {
        $sandbox = app(Sandbox::class);
        $user = $this->createUser();
        Event::fake([SandboxChanged::class]);

        try {
            $sandbox->transaction(TestUser::class, function () use ($user): void {
                $user->update(['name' => 'Changed']);

                throw new RuntimeException('Operation failed');
            });
            $this->fail('Operation failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Operation failed', $exception->getMessage());
        }

        Event::assertNotDispatched(SandboxChanged::class);
        $this->assertSame('Test User', $user->fresh()->name);
    }

    #[Test]
    public function completionUsesTheBackendConnectionWhenTheDefaultDiffers(): void
    {
        $sandbox = new Sandbox(backend: new EloquentSandboxBackend(
            app(SandboxModelRegistry::class),
            statusModel: (new SandboxStatus())->setConnection('testing'),
        ));
        $user = $this->createUser()->setConnection('testing');
        $model = new class extends TestUser
        {
            protected $connection = 'testing';
        };
        Event::listen(SandboxChanged::class, function (SandboxChanged $event) use ($sandbox): void {
            $this->assertSame($sandbox->connection(), $event->connection);

            throw new RuntimeException('Preparation failed on the backend connection');
        });
        config(['database.connections.unrelated' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::setDefaultConnection('unrelated');

        try {
            $sandbox->transaction($model::class, fn () => $user->update(['name' => 'Changed']));
            $this->fail('Listener failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Preparation failed on the backend connection', $exception->getMessage());
        } finally {
            DB::setDefaultConnection('testing');
        }

        $this->assertSame('Test User', $user->fresh()->name);
    }

    #[Test]
    public function mismatchedModelConnectionsAreRejectedBeforeTheCallback(): void
    {
        $sandbox = app(Sandbox::class);
        $model = new class extends TestUser
        {
            protected $connection = 'unrelated';
        };
        config(['database.connections.unrelated' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        Event::fake([SandboxChanged::class]);

        try {
            $sandbox->transaction($model::class, fn () => $this->fail('An unprotected operation started.'));
            $this->fail('Mismatched model connection was accepted.');
        } catch (SandboxException $exception) {
            $this->assertSame(SandboxException::CODE_MODEL_NOT_REGISTERED, $exception->getCode());
        }

        Event::assertNotDispatched(SandboxChanged::class);
    }

    #[Test]
    public function transactionsPreserveOwnershipAndTheExistingTableSelection(): void
    {
        $sandbox = app(Sandbox::class);
        $model = new class extends TestUser
        {
            use HasSandbox;
        };
        $sandbox->models($model::class);
        $model::useSandbox();
        $status = $sandbox->status()->getAttributes();
        Event::listen(SandboxChanged::class, function () use ($model): void {
            $this->assertTrue((new $model())->isUsingSandboxTable());
        });

        try {
            $sandbox->transaction($model::class, function () use ($model): void {
                $this->assertTrue((new $model())->isUsingSandboxTable());
            });

            $this->assertTrue((new $model())->isUsingSandboxTable());
            $this->assertSame($status, $sandbox->status()->getAttributes());
        } finally {
            $model::useActive();
        }
    }
}
