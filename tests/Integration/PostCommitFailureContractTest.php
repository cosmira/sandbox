<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxStatus as State;
use Cosmira\Sandbox\Events\SandboxCommitted;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class PostCommitFailureContractTest extends TestCase
{
    private ?string $databaseFile = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        if ($app['config']->get('database.connections.testing.driver') === 'sqlite') {
            $this->databaseFile = tempnam(sys_get_temp_dir(), 'sandbox-post-commit-');
            $app['config']->set('database.connections.testing.database', $this->databaseFile);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['delivery_items', 'delivery_items_sb'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->integer('id')->primary();
                $blueprint->string('name');
                $blueprint->dateTime('change_date')->nullable();
            });
        }
        DB::table('delivery_items')->insert(['id' => 1, 'name' => 'active']);
        app(Sandbox::class)->models(DeliveryItem::class);
    }

    protected function tearDown(): void
    {
        $database = DB::getFacadeRoot();

        try {
            DeliveryItem::useActive();
            DB::purge('observer');
            Schema::dropIfExists('delivery_items_sb');
            Schema::dropIfExists('delivery_items');
        } finally {
            try {
                parent::tearDown();
            } finally {
                foreach ($database->getConnections() as $connection) {
                    $connection->disconnect();
                }
                if ($this->databaseFile !== null && is_file($this->databaseFile)) {
                    unlink($this->databaseFile);
                }
            }
        }
    }

    public static function transitions(): array
    {
        return [
            'open'   => [false, SandboxOpened::class, State::Locked, 'active', 0],
            'commit' => [true, SandboxCommitted::class, State::Free, 'edited', 1],
        ];
    }

    #[Test]
    #[DataProvider('transitions')]
    public function listenerFailurePreservesCommittedResult(bool $commit, string $event, State $state, string $active, int $changeId): void
    {
        $sandbox = app(Sandbox::class);
        if ($commit) {
            $sandbox->edit(1, fn () => DeliveryItem::query()->where('id', 1)->update(['name' => 'edited']));
        }
        Event::listen($event, static function (): never {
            throw new RuntimeException('delivery failed after commit');
        });

        try {
            if ($commit) {
                $sandbox->commit(1);
            } else {
                $sandbox->edit(1, fn () => DeliveryItem::query()->where('id', 1)->update(['name' => 'edited']));
            }
            $this->fail('The delivery exception must remain visible.');
        } catch (RuntimeException $exception) {
            $this->assertSame('delivery failed after commit', $exception->getMessage());
        }

        $this->assertSame(0, $sandbox->connection()->transactionLevel());
        $this->assertFalse(DeliveryItem::isUsingSandbox());
        config()->set('database.connections.observer', DB::connection()->getConfig());
        $observer = DB::connection('observer');
        $this->assertNotSame(DB::connection()->getPdo(), $observer->getPdo());
        $status = $observer->table('sandbox_status')->first();
        $this->assertSame($state->value, (int) $status->status);
        $this->assertSame('1', (string) $status->user_id);
        $this->assertSame($changeId, (int) $status->change_id);
        $this->assertSame($active, $observer->table('delivery_items')->value('name'));
        $this->assertSame('edited', $observer->table('delivery_items_sb')->value('name'));
    }
}

class DeliveryItem extends Model
{
    use HasSandbox;

    protected $table = 'delivery_items';

    public $timestamps = false;
}
