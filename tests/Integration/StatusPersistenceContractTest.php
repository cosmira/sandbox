<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxStatus as State;
use Cosmira\Sandbox\Events\SandboxCommitted;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\Events\SandboxRolledBack;
use Cosmira\Sandbox\Events\SandboxSaved;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class StatusPersistenceContractTest extends TestCase
{
    protected function tearDown(): void
    {
        try {
            Schema::dropIfExists('status_contract_items_sb');
            Schema::dropIfExists('status_contract_items');
        } finally {
            parent::tearDown();
        }
    }

    public static function vetoedOperations(): iterable
    {
        foreach (['saving', 'updating'] as $event) {
            foreach (['edit', 'commit', 'save', 'rollback'] as $operation) {
                yield $event.' vetoes '.$operation => [$event, $operation];
            }
        }
    }

    #[Test]
    #[DataProvider('vetoedOperations')]
    public function rejectedStatusPersistenceRollsBackDataAndDoesNotEmitSuccess(string $event, string $operation): void
    {
        foreach (['status_contract_items', 'status_contract_items_sb'] as $name) {
            Schema::dropIfExists($name);
            Schema::create($name, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('name');
            });
        }
        DB::table('status_contract_items')->insert(['id' => 1, 'name' => 'Active']);
        DB::table('status_contract_items_sb')->insert(['id' => 1, 'name' => 'Draft']);
        SandboxStatus::query()->update([
            'status'  => $operation === 'edit' ? State::Free : State::Locked,
            'user_id' => 1,
        ]);
        $before = SandboxStatus::firstOrFail()->getAttributes();
        $sandbox = app(Sandbox::class);
        $sandbox->models(StatusPersistenceModel::class);
        Event::fake([SandboxOpened::class, SandboxCommitted::class, SandboxSaved::class, SandboxRolledBack::class]);
        $eventName = 'eloquent.'.$event.': '.SandboxStatus::class;
        $previousListeners = Event::getRawListeners()[$eventName] ?? [];
        Event::listen($eventName, fn (): bool => false);

        try {
            if ($operation === 'edit') {
                $sandbox->edit(1, fn () => $this->fail('Editing proceeded after its status update was vetoed.'));
            } else {
                $sandbox->{$operation}(1);
            }
            $this->fail('The rejected status update was ignored.');
        } catch (SandboxException $exception) {
            $this->assertSame('Sandbox status update was rejected.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
            foreach ($previousListeners as $listener) {
                Event::listen($eventName, $listener);
            }
        }

        $this->assertSame($before, SandboxStatus::firstOrFail()->getAttributes());
        $this->assertSame('Active', DB::table('status_contract_items')->value('name'));
        $this->assertSame('Draft', DB::table('status_contract_items_sb')->value('name'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(StatusPersistenceModel::isUsingSandbox());
        Event::assertNothingDispatched();
    }
}

class StatusPersistenceModel extends Model
{
    use HasSandbox;

    protected $table = 'status_contract_items';

    public $timestamps = false;

    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return null;
    }
}
