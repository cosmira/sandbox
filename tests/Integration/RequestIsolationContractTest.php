<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxStatus as State;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Http\Middleware\SandboxMiddleware;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Cosmira\Sandbox\Tests\TestUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

final class RequestIsolationContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestItem::useActive();
        foreach (['request_items', 'request_items_sb'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->integer('id')->primary();
                $blueprint->string('name');
                $blueprint->dateTime('change_date')->nullable();
            });
        }
        DB::table('request_items')->insert(['id' => 1, 'name' => 'active']);
        DB::table('request_items_sb')->insert(['id' => 1, 'name' => 'draft']);
        app(SandboxModelRegistry::class)->register(RequestItem::class);
    }

    protected function tearDown(): void
    {
        RequestItem::useActive();
        Schema::dropIfExists('request_items_sb');
        Schema::dropIfExists('request_items');
        parent::tearDown();
    }

    public static function readCases(): array
    {
        return [
            'free owner'   => [State::Free, 1, 'active'],
            'locked owner' => [State::Locked, 1, 'draft'],
            'locked other' => [State::Locked, 2, 'active'],
            'locked guest' => [State::Locked, null, 'active'],
            'saved owner'  => [State::Saved, 1, 'draft'],
            'saved other'  => [State::Saved, 2, 'draft'],
            'saved guest'  => [State::Saved, null, 'active'],
        ];
    }

    #[Test]
    #[DataProvider('readCases')]
    public function readsRespectVisibilityAndAlwaysRestore(State $state, ?int $user, string $expected): void
    {
        SandboxStatus::query()->update(['status' => $state, 'user_id' => 1]);
        $request = $this->request('GET', $user);
        $response = (new SandboxMiddleware())->handle($request, fn () => new Response(RequestItem::query()->value('name')));
        $this->assertSame($expected, $response->getContent());
        $this->assertFalse(RequestItem::isUsingSandbox());
    }

    public static function errors(): array
    {
        $cases = [];
        foreach ([State::Free, State::Locked, State::Saved] as $state) {
            foreach ([400, 401, 403, 404, 422, 500] as $code) {
                $cases[$state->name.' '.$code] = [$state, $code];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('errors')]
    public function errorResponsesRollBackOpeningAndData(State $state, int $code): void
    {
        SandboxStatus::query()->update(['status' => $state, 'user_id' => $state === State::Saved ? 2 : 1]);
        $before = SandboxStatus::firstOrFail()->getAttributes();
        Event::fake([SandboxOpened::class]);
        $response = (new SandboxMiddleware())->handle($this->request('PATCH', 1), function () use ($code): Response {
            RequestItem::query()->where('id', 1)->update(['name' => 'partial']);

            return new Response('rejected', $code);
        });
        $this->assertSame($code, $response->getStatusCode());
        $this->assertSame($before, SandboxStatus::firstOrFail()->getAttributes());
        $this->assertSame('active', DB::table('request_items')->value('name'));
        $this->assertSame('draft', DB::table('request_items_sb')->value('name'));
        $this->assertFalse(RequestItem::isUsingSandbox());
        Event::assertNotDispatched(SandboxOpened::class);
    }

    #[Test]
    public function savedDraftCanBeResumedWithoutReplacingItsData(): void
    {
        SandboxStatus::query()->update(['status' => State::Saved, 'user_id' => 1]);
        (new SandboxMiddleware())->handle($this->request('PATCH', 2), function (): Response {
            $this->assertSame('draft', RequestItem::query()->value('name'));

            return new Response('ok');
        });
        $this->assertTrue(SandboxStatus::firstOrFail()->isLockedBy(2));
        $this->assertSame('active', DB::table('request_items')->value('name'));
        $this->assertFalse(RequestItem::isUsingSandbox());
    }

    #[Test]
    public function repeatedOwnerOpenDoesNotProduceAnotherTransition(): void
    {
        app(Sandbox::class)->open(1);
        $before = SandboxStatus::firstOrFail()->getAttributes();
        Event::fake([SandboxOpened::class]);
        app(Sandbox::class)->open(1, note: 'retry');
        $this->assertSame($before, SandboxStatus::firstOrFail()->getAttributes());
        Event::assertNotDispatched(SandboxOpened::class);
    }

    #[Test]
    public function thrownExceptionRollsBackPartialDataAndNewOwnership(): void
    {
        Event::fake([SandboxOpened::class]);

        try {
            (new SandboxMiddleware())->handle($this->request('PATCH', 1), function (): never {
                RequestItem::query()->where('id', 1)->update(['name' => 'partial']);

                throw new \RuntimeException('controller failed');
            });
            $this->fail('Controller exception was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('controller failed', $exception->getMessage());
        }

        $this->assertTrue(SandboxStatus::firstOrFail()->isFree());
        $this->assertSame('active', DB::table('request_items')->value('name'));
        $this->assertSame('draft', DB::table('request_items_sb')->value('name'));
        $this->assertFalse(RequestItem::isUsingSandbox());
        Event::assertNotDispatched(SandboxOpened::class);
    }

    #[Test]
    public function sequentialUsersDoNotInheritEachOthersTableContext(): void
    {
        $middleware = new SandboxMiddleware();
        $write = $this->request('PATCH', 1);
        $middleware->handle($write, function (): Response {
            RequestItem::query()->where('id', 1)->update(['name' => 'private draft']);

            return new Response('ok');
        });

        $other = $middleware->handle($this->request('GET', 2), fn () => new Response(RequestItem::query()->value('name')));
        $owner = Request::create('/items', 'GET');
        $owner->setUserResolver($write->getUserResolver());
        $own = $middleware->handle($owner, fn () => new Response(RequestItem::query()->value('name')));

        $this->assertSame('active', $other->getContent());
        $this->assertSame('private draft', $own->getContent());
        $this->assertFalse(RequestItem::isUsingSandbox());
    }

    private function request(string $method, ?int $user): Request
    {
        $request = Request::create('/items', $method);
        if ($user !== null) {
            $actor = new TestUser();
            $actor->forceFill([
                'id'       => $user,
                'name'     => 'Request user '.$user,
                'email'    => 'request-'.$user.'@example.test',
                'password' => 'not-used',
            ])->save();
            $request->setUserResolver(fn () => $actor);
        }

        return $request;
    }
}

class RequestItem extends Model
{
    use HasSandbox;

    protected $table = 'request_items';

    public $timestamps = false;
}
