<?php

declare(strict_types=1);

use Cosmira\Sandbox\Backends\EloquentSandboxBackend;
use Cosmira\Sandbox\Contracts\SandboxBackend;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2).'/vendor/autoload.php';

class ConcurrentItem extends Model
{
    use HasSandbox;

    protected $table = 'concurrent_items';

    public $timestamps = false;

    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return null;
    }
}

function signal(string $message): void
{
    fwrite(STDOUT, $message."\n");
    fflush(STDOUT);
}

try {
    $configuration = json_decode((string) getenv('SANDBOX_WORKER_CONNECTION'), true, 512, JSON_THROW_ON_ERROR);
    $driver = $configuration['driver'];
    $database = $configuration['database'];
    $isolatedSqlite = $driver === 'sqlite'
        && str_starts_with(basename($database), 'sandbox-concurrency-')
        && realpath(dirname($database)) === realpath(sys_get_temp_dir());
    $isolatedNative = in_array($driver, ['pgsql', 'mysql'], true)
        && getenv('SANDBOX_TEST_ALLOW_DESTRUCTIVE') === '1'
        && preg_match('/\Asandbox_contract(?:_[a-z0-9]+)?\z/', $database);
    if (! $isolatedSqlite && ! $isolatedNative) {
        throw new RuntimeException('Concurrency worker requires an isolated test database.');
    }

    $container = new Container();
    Container::setInstance($container);
    $container->instance('config', new Repository(['sandbox' => ['table' => 'sandbox_status']]));
    $container->instance('log', new NullLogger());
    $container->instance('events', new Dispatcher($container));
    $container->instance('db.transactions', new DatabaseTransactionsManager());
    $capsule = new Manager($container);
    $capsule->addConnection($configuration, 'testing');
    $capsule->getDatabaseManager()->setDefaultConnection('testing');
    $capsule->bootEloquent();
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::setFacadeApplication($container);
    $capsule->getConnection()->setTransactionManager($container->make('db.transactions'));
    $container->singleton(SandboxModelRegistry::class);
    $container->singleton(SandboxBackend::class, EloquentSandboxBackend::class);
    $container->make(SandboxModelRegistry::class)->register(ConcurrentItem::class);
    $sandbox = $container->make(Sandbox::class);
    $operation = $argv[1] ?? '';

    if ($operation === 'hold') {
        $sandbox->edit(1, function (): void {
            ConcurrentItem::query()->where('id', 1)->update(['name' => 'edited draft']);
            signal('READY');
            $read = [STDIN];
            $write = $except = [];
            if (stream_select($read, $write, $except, 15) !== 1 || trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Timed out waiting for the release barrier.');
            }
        });
    } elseif (in_array($operation, ['open', 'save', 'commit', 'rollback'], true)) {
        signal('ATTEMPT');
        $sandbox->{$operation}($operation === 'open' ? 2 : 1);
    } else {
        throw new RuntimeException('Unknown concurrency operation.');
    }

    signal('RESULT '.json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR));
} catch (SandboxException $exception) {
    signal('RESULT '.json_encode(['result' => 'sandbox_exception', 'code' => $exception->getCode()], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
