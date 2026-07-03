<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Events\ResolvingSandboxModels;
use Cosmira\Sandbox\Events\SandboxClosed;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ResolvingSandboxModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ResolvingSandboxModels::restoreActiveTables();
        SandboxEventModelStub::useActiveTable();
    }

    #[Test]
    public function eventSwitchesModelsToSandboxTables(): void
    {
        $event = new ResolvingSandboxModels(Request::create('/categories', 'POST'));

        $event->models(SandboxEventModelStub::class);

        $this->assertTrue(SandboxEventModelStub::isUsingSandboxTable());
    }

    #[Test]
    public function eventRejectsClassesThatCannotSwitchTables(): void
    {
        $event = new ResolvingSandboxModels(Request::create('/categories', 'POST'));

        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);

        $event->models(NonSandboxEventModelStub::class);
    }

    #[Test]
    public function eventRejectsPartialSandboxApiClasses(): void
    {
        $event = new ResolvingSandboxModels(Request::create('/categories', 'POST'));

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
    public function sandboxClosedRestoresResolvedModels(): void
    {
        $event = new ResolvingSandboxModels(Request::create('/categories', 'POST'));
        $event->models(SandboxEventModelStub::class);

        Event::dispatch(new SandboxClosed(
            userId: 1,
            result: SandboxOperation::Rollback,
            closedAt: now(),
            note: null,
            asyncUpdater: false,
        ));

        $this->assertFalse(SandboxEventModelStub::isUsingSandboxTable());
    }

    #[Test]
    public function sandboxSaveKeepsResolvedModelsInSandboxMode(): void
    {
        $event = new ResolvingSandboxModels(Request::create('/categories', 'POST'));
        $event->models(SandboxEventModelStub::class);

        Event::dispatch(new SandboxClosed(
            userId: 1,
            result: SandboxOperation::Save,
            closedAt: now(),
            note: null,
            asyncUpdater: false,
        ));

        $this->assertTrue(SandboxEventModelStub::isUsingSandboxTable());

        ResolvingSandboxModels::restoreActiveTables();
    }
}

class SandboxEventModelStub extends Model
{
    use HasSandbox;

    protected $table = 'sandbox_event_items';
}

class NonSandboxEventModelStub extends Model
{
    protected $table = 'non_sandbox_event_items';
}

class PartialSandboxEventModelWithUseOnlyStub extends Model
{
    public static function useSandboxTable(): void {}

    public static function useActiveTable(): void {}
}

class PartialSandboxEventModelWithActiveOnlyStub extends Model
{
    public static function isUsingSandboxTable(): bool
    {
        return true;
    }

    public static function useActiveTable(): void {}
}

class PartialSandboxEventModelWithoutUseStub extends Model
{
    public static function isUsingSandboxTable(): bool
    {
        return false;
    }

    public static function useActiveTable(): void {}
}
