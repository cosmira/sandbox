<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Events\SandboxResolvingModels;
use Cosmira\Sandbox\Events\SandboxRolledBack;
use Cosmira\Sandbox\Events\SandboxSaved;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class SandboxResolvingModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SandboxResolvingModels::restoreActiveTables();
        SandboxEventModelStub::useActive();
    }

    #[Test]
    public function eventSwitchesModelsToSandboxTables(): void
    {
        $event = new SandboxResolvingModels(Request::create('/categories', 'POST'));

        $event->models(SandboxEventModelStub::class);

        $this->assertTrue(SandboxEventModelStub::isUsingSandbox());
    }

    #[Test]
    public function eventUsesTheInjectedRegistry(): void
    {
        $registry = new TrackingSandboxEventRegistry();
        $event = new SandboxResolvingModels(Request::create('/categories', 'POST'), $registry);

        $event->models(SandboxEventModelStub::class);

        $this->assertSame([[SandboxEventModelStub::class]], $registry->resolvedModels);
    }

    #[Test]
    public function eventRejectsClassesThatCannotSwitchTables(): void
    {
        $event = new SandboxResolvingModels(Request::create('/categories', 'POST'));

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        $event->models(NonSandboxEventModelStub::class);
    }

    #[Test]
    public function eventRejectsPartialSandboxApiClasses(): void
    {
        $event = new SandboxResolvingModels(Request::create('/categories', 'POST'));

        foreach ([
            PartialSandboxEventModelWithUseOnlyStub::class,
            PartialSandboxEventModelWithActiveOnlyStub::class,
            PartialSandboxEventModelWithoutUseStub::class,
        ] as $model) {
            try {
                $event->models($model);
            } catch (SandboxException $exception) {
                $this->assertSame(
                    SandboxException::CODE_MODEL_NOT_REGISTERED,
                    $exception->getCode(),
                );

                continue;
            }

            $this->fail($model.' was accepted without the full sandbox API.');
        }
    }

    #[Test]
    public function sandboxRolledBackRestoresResolvedModels(): void
    {
        $event = new SandboxResolvingModels(Request::create('/categories', 'POST'));
        $event->models(SandboxEventModelStub::class);

        Event::dispatch(new SandboxRolledBack(
            userId: 1,
            rolledBackAt: now(),
            note: null,
        ));

        $this->assertFalse(SandboxEventModelStub::isUsingSandbox());
    }

    #[Test]
    public function sandboxSaveKeepsResolvedModelsInSandboxMode(): void
    {
        $event = new SandboxResolvingModels(Request::create('/categories', 'POST'));
        $event->models(SandboxEventModelStub::class);

        Event::dispatch(new SandboxSaved(
            userId: 1,
            savedAt: now(),
            note: null,
        ));

        $this->assertTrue(SandboxEventModelStub::isUsingSandbox());

        SandboxResolvingModels::restoreActiveTables();
    }
}

class SandboxEventModelStub extends Model
{
    use HasSandbox;

    protected $table = 'sandbox_event_items';
}

class TrackingSandboxEventRegistry extends SandboxModelRegistry
{
    /**
     * The model batches requested by the event.
     *
     * @var array<int, array<int, class-string>>
     */
    public array $resolvedModels = [];

    public function useSandbox(string ...$models): void
    {
        $this->resolvedModels[] = $models;
    }
}

class NonSandboxEventModelStub extends Model
{
    protected $table = 'non_sandbox_event_items';
}

class PartialSandboxEventModelWithUseOnlyStub extends Model
{
    public static function useSandbox(): void {}

    public static function useActive(): void {}
}

class PartialSandboxEventModelWithActiveOnlyStub extends Model
{
    public static function isUsingSandbox(): bool
    {
        return true;
    }

    public static function useActive(): void {}
}

class PartialSandboxEventModelWithoutUseStub extends Model
{
    public static function isUsingSandbox(): bool
    {
        return false;
    }

    public static function useActive(): void {}
}
