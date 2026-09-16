<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Backends\EloquentSandboxBackend;
use Cosmira\Sandbox\Enums\SandboxStatus as State;
use Cosmira\Sandbox\Events\SandboxResetting;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Support\SandboxRecordRestorer;
use Cosmira\Sandbox\Support\SandboxStatusLocker;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class RefactoredLifecycleContractTest extends TestCase
{
    #[Test]
    public function restoringAnOrdinaryModelReportsTheMissingContract(): void
    {
        $this->expectException(SandboxException::class);
        $this->expectExceptionCode(SandboxException::CODE_MODEL_NOT_REGISTERED);
        $this->expectExceptionMessage('must use HasSandbox trait');

        (new SandboxRecordRestorer())->restore(new class extends Model {});
    }

    #[Test]
    public function rememberingAModelTwiceRestoresItsOriginalSelection(): void
    {
        $registry = new SandboxModelRegistry();
        RefactoredModel::useActive();

        $registry->usingTables(true, function () use ($registry): void {
            $registry->useSandbox(RefactoredModel::class);
            $registry->useSandbox(RefactoredModel::class);
            $this->assertTrue(RefactoredModel::isUsingSandbox());
        });

        $this->assertFalse(RefactoredModel::isUsingSandbox());
    }

    #[Test]
    public function completedNestedContextsDoNotCaptureLaterModelSelections(): void
    {
        $registry = new SandboxModelRegistry();
        RefactoredModel::useActive();

        $registry->usingTables(true, function () use ($registry): void {
            $registry->usingTables(false, static fn () => null);
            $registry->useSandbox(RefactoredModel::class);
            $this->assertTrue(RefactoredModel::isUsingSandbox());
        });

        $this->assertFalse(RefactoredModel::isUsingSandbox());
    }

    #[Test]
    public function everyRegisteredTableParticipatesInResetAndApply(): void
    {
        $registry = new SandboxModelRegistry();
        $tables = ['first_refactor', 'second_refactor'];

        try {
            foreach ($tables as $name) {
                foreach ([$name, $name.'_sb'] as $physical) {
                    Schema::create($physical, function (Blueprint $table): void {
                        $table->integer('id')->primary();
                        $table->string('value');
                    });
                }
                DB::table($name)->insert(['id' => 1, 'value' => 'active']);
                $registry->registerTables(DB::connection(), new SandboxTable($name, ['id']));
            }
            $registry->resetSandbox();
            foreach ($tables as $name) {
                $this->assertSame('active', DB::table($name.'_sb')->value('value'));
                DB::table($name.'_sb')->update(['value' => 'published']);
            }
            $registry->applySandbox();
            foreach ($tables as $name) {
                $this->assertSame('published', DB::table($name)->value('value'));
            }
        } finally {
            foreach ($tables as $name) {
                Schema::dropIfExists($name.'_sb');
                Schema::dropIfExists($name);
            }
        }
    }

    #[Test]
    public function externalConnectionValidationRejectsMismatchedConnections(): void
    {
        $registry = new SandboxModelRegistry();
        $registry->registerTables(DB::connection(), new SandboxTable('registered', ['id']));
        $registry->ensureTableConnection(DB::connection());
        $this->expectException(SandboxException::class);
        $registry->ensureTableConnection($this->createStub(Connection::class));
    }

    #[Test]
    public function statusQueryRequestsARowLockInsideTheTransaction(): void
    {
        DB::transaction(function (): void {
            $query = (new SandboxStatusLocker())->query(new SandboxStatus());
            $this->assertTrue($query->getQuery()->lock);
            $this->assertNotNull($query->first());
        });
    }

    #[Test]
    public function customInitializationCanExtendTheDefaultInitialization(): void
    {
        $backend = new class(new SandboxModelRegistry()) extends EloquentSandboxBackend
        {
            public bool $initialized = false;

            protected function initializeDraft(): void
            {
                parent::initializeDraft();
                $this->initialized = true;
            }
        };
        SandboxStatus::query()->update(['status' => State::Free, 'user_id' => null]);

        $backend->open(1);

        $this->assertTrue($backend->initialized);
        $this->assertTrue($backend->status()->isLockedBy(1));
    }

    #[Test]
    public function forcingAFreeDraftEmitsOnlyOneResetEvent(): void
    {
        SandboxStatus::query()->update(['status' => State::Free, 'user_id' => 2]);
        Event::fake([SandboxResetting::class]);

        (new EloquentSandboxBackend(new SandboxModelRegistry()))->open(1, true);

        Event::assertDispatchedTimes(SandboxResetting::class, 1);
    }
}

class RefactoredModel extends Model
{
    use HasSandbox;
}
