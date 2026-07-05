<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Events\ResolvingSandboxModels;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Http\Middleware\SandboxMiddleware;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SandboxMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ResolvingSandboxModels::restoreActiveTables();
        MiddlewareSandboxModelStub::useActiveTable();
    }

    #[Test]
    public function readRequestsKeepModelsOnActiveTablesWhenSandboxIsFree(): void
    {
        SandboxStatus::query()->update(['status' => SandboxStatusEnum::Free]);
        Event::fake([ResolvingSandboxModels::class]);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'GET');

        $middleware->handle($request, function (): string {
            $this->assertFalse(MiddlewareSandboxModelStub::isUsingSandboxTable());

            return 'response';
        });

        $this->assertFalse(MiddlewareSandboxModelStub::isUsingSandboxTable());
        Event::assertNotDispatched(ResolvingSandboxModels::class);
    }

    #[Test]
    public function readRequestsUseSandboxTablesWhenSandboxIsActive(): void
    {
        SandboxStatus::query()->update(['status' => SandboxStatusEnum::Locked]);

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(MiddlewareSandboxModelStub::class);
        });

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'GET');

        $middleware->handle($request, function (): string {
            $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());

            return 'response';
        });

        $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());
    }

    #[Test]
    public function readRequestsResolveSandboxModelsWhenSandboxIsActive(): void
    {
        SandboxStatus::query()->update(['status' => SandboxStatusEnum::Saved]);
        Event::fake([ResolvingSandboxModels::class]);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'GET');

        $middleware->handle($request, fn (): string => 'response');

        Event::assertDispatched(ResolvingSandboxModels::class);
    }

    #[Test]
    public function readRequestsUseTheInjectedRegistryWhenSandboxIsActive(): void
    {
        SandboxStatus::query()->update(['status' => SandboxStatusEnum::Saved]);

        $registry = new TrackingSandboxMiddlewareRegistry();
        $middleware = new SandboxMiddleware($registry);
        $request = Request::create('/categories', 'GET');

        $middleware->handle($request, fn (): string => 'response');

        $this->assertSame([[]], $registry->resolvedModels);
    }

    #[Test]
    public function writeRequestsRouteModelsThroughSandboxDuringTheRequest(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $middleware = new SandboxMiddleware();
        $user = $this->createUser(id: 1);
        $request = Request::create('/categories', 'POST');
        $request->setUserResolver(fn () => $user);

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(MiddlewareSandboxModelStub::class);
        });

        $response = $middleware->handle($request, function (): string {
            $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());

            return 'response';
        });

        $this->assertSame('response', $response);
        $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());
    }

    #[Test]
    public function writeRequestsOpenFreeSandboxForTheCurrentUser(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);

        $middleware = new SandboxMiddleware();
        $user = $this->createUser(id: 1);
        $request = Request::create('/categories', 'POST');
        $request->setUserResolver(fn () => $user);

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(MiddlewareSandboxModelStub::class);
        });

        $response = $middleware->handle($request, function (): string {
            $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());

            return 'response';
        });

        $status = SandboxStatus::first();

        $this->assertSame('response', $response);
        $this->assertTrue($status?->isLocked());
        $this->assertSame('1', (string) $status?->user_id);
    }

    #[Test]
    public function writeRequestsUseTheInjectedSandboxWhenOpeningFreeSandbox(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);

        $sandbox = new TrackingSandboxMiddlewareSandbox();
        $middleware = new SandboxMiddleware(sandbox: $sandbox);
        $request = Request::create('/categories', 'POST');
        $request->setUserResolver(fn () => new StringIdentifierUserStub('7'));

        $response = $middleware->handle($request, fn (): string => 'response');

        $this->assertSame('response', $response);
        $this->assertSame(['7'], $sandbox->openedFor);
    }

    #[Test]
    public function writeRequestsOpenFreeSandboxEvenWhenItKeepsAStaleOwner(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => 5,
        ]);

        $middleware = new SandboxMiddleware();
        $user = $this->createUser(id: 1);
        $request = Request::create('/categories', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn (): string => 'response');
        $status = SandboxStatus::first();

        $this->assertSame('response', $response);
        $this->assertTrue($status?->isLocked());
        $this->assertSame('1', (string) $status?->user_id);
    }

    #[Test]
    public function writeRequestsAreRejectedForGuestsWhenSandboxIsFree(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Free,
            'user_id' => null,
        ]);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'POST');

        try {
            $middleware->handle($request, fn (): string => 'response');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertTrue(SandboxStatus::first()?->isFree());

            return;
        }

        $this->fail('Guest request opened the sandbox.');
    }

    #[Test]
    public function writeRequestsAreAllowedForTheSandboxOwner(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'POST');
        $request->setUserResolver(fn () => $this->createUser(id: 1));

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(MiddlewareSandboxModelStub::class);
        });

        $response = $middleware->handle($request, function (): string {
            $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());

            return 'response';
        });

        $this->assertSame('response', $response);
    }

    #[Test]
    public function writeRequestsCompareOwnerIdsByStringValue(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'POST');
        $request->setUserResolver(fn () => new StringIdentifierUserStub('1'));

        $response = $middleware->handle($request, fn (): string => 'response');

        $this->assertSame('response', $response);
    }

    #[Test]
    public function writeRequestsAreRejectedForOtherUsers(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'POST');
        $request->setUserResolver(fn () => $this->createUser(id: 2));

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(MiddlewareSandboxModelStub::class);
        });

        try {
            $middleware->handle($request, fn (): string => 'response');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertFalse(MiddlewareSandboxModelStub::isUsingSandboxTable());

            return;
        }

        $this->fail('Request was not rejected.');
    }

    #[Test]
    public function writeRequestsAreRejectedForGuestsWhenSandboxHasAnOwner(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'DELETE');

        try {
            $middleware->handle($request, fn (): string => 'response');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertTrue(SandboxStatus::first()?->isLocked());

            return;
        }

        $this->fail('Guest request modified an owned sandbox.');
    }

    #[Test]
    public function writeRequestsAreRejectedWhenTheStatusRowIsMissing(): void
    {
        SandboxStatus::query()->delete();

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories', 'POST');
        $request->setUserResolver(fn () => new StringIdentifierUserStub('1'));

        try {
            $middleware->handle($request, fn (): string => 'response');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail('Request modified data without a sandbox status row.');
    }

    #[Test]
    public function writeRequestsKeepPreviousSandboxState(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        MiddlewareSandboxModelStub::useSandboxTable();

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories/1', 'DELETE');
        $request->setUserResolver(fn () => $this->createUser(id: 1));

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(MiddlewareSandboxModelStub::class);
        });

        $middleware->handle($request, function (): string {
            $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());

            return 'response';
        });

        $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());
    }

    #[Test]
    public function writeRequestsKeepSandboxTablesWhenTheRequestFails(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $this->expectException(RuntimeException::class);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories/1', 'PATCH');
        $request->setUserResolver(fn () => $this->createUser(id: 1));

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(MiddlewareSandboxModelStub::class);
        });

        try {
            $middleware->handle($request, function (): never {
                throw new RuntimeException('Request failed.');
            });
        } finally {
            $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());
        }
    }

    #[Test]
    public function terminateRestoresRequestLocalSandboxTableSwitches(): void
    {
        SandboxStatus::query()->update([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 1,
        ]);

        $middleware = new SandboxMiddleware();
        $request = Request::create('/categories/1', 'PATCH');
        $request->setUserResolver(fn () => $this->createUser(id: 1));

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(MiddlewareSandboxModelStub::class);
        });

        $response = $middleware->handle($request, function (): string {
            $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());

            return 'response';
        });

        $this->assertTrue(MiddlewareSandboxModelStub::isUsingSandboxTable());

        $middleware->terminate($request, $response);

        $this->assertFalse(MiddlewareSandboxModelStub::isUsingSandboxTable());
        $this->assertTrue(SandboxStatus::first()?->isLocked());
    }
}

class MiddlewareSandboxModelStub extends Model
{
    use HasSandbox;

    protected $table = 'middleware_items';
}

class TrackingSandboxMiddlewareRegistry extends SandboxModelRegistry
{
    /**
     * The model batches requested by the middleware.
     *
     * @var array<int, array<int, class-string>>
     */
    public array $resolvedModels = [];

    public function useSandboxTables(string ...$models): void
    {
        $this->resolvedModels[] = $models;
    }
}

class TrackingSandboxMiddlewareSandbox extends Sandbox
{
    /**
     * The users passed to the injected sandbox lifecycle manager.
     *
     * @var array<int, int|string>
     */
    public array $openedFor = [];

    public function open(int|string|Model $user, bool $force = false, ?string $note = null): void
    {
        $this->openedFor[] = $user instanceof Model ? $user->getKey() : $user;
    }

    public function status(): ?SandboxStatus
    {
        return new SandboxStatus([
            'status'  => SandboxStatusEnum::Locked,
            'user_id' => 7,
        ]);
    }
}

class StringIdentifierUserStub implements Authenticatable
{
    public function __construct(private readonly string $id) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
