<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxStatus as State;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

#[Group('concurrency')]
final class ConcurrencyContractTest extends TestCase
{
    private ?string $databaseFile = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        if ($app['config']->get('database.connections.testing.driver') === 'sqlite') {
            $temporaryFile = tempnam(sys_get_temp_dir(), 'sandbox-concurrency-');
            $this->databaseFile = $temporaryFile.'.sandbox-concurrency.sqlite';
            rename($temporaryFile, $this->databaseFile);
            $app['config']->set('database.connections.testing.database', $this->databaseFile);
            $app['config']->set('database.connections.testing.transaction_mode', 'DEFERRED');
            $app['config']->set('database.connections.testing.busy_timeout', 10000);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['concurrent_items', 'concurrent_items_sb'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->integer('id')->primary();
                $blueprint->string('name');
            });
        }
    }

    protected function tearDown(): void
    {
        $database = DB::getFacadeRoot();

        try {
            Schema::dropIfExists('concurrent_items_sb');
            Schema::dropIfExists('concurrent_items');
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

    public static function operations(): array
    {
        return [
            'another opener'       => ['open', State::Locked, 'active', 'edited draft'],
            'save during edit'     => ['save', State::Saved, 'active', 'edited draft'],
            'commit during edit'   => ['commit', State::Free, 'edited draft', 'edited draft'],
            'rollback during edit' => ['rollback', State::Free, 'active', 'active'],
        ];
    }

    #[Test]
    #[DataProvider('operations')]
    public function competingOperationsObserveTheCommittedEdit(string $operation, State $expectedState, string $active, string $draft): void
    {
        $repetitions = filter_var(getenv('SANDBOX_CONCURRENCY_REPETITIONS') ?: '1', FILTER_VALIDATE_INT);
        $this->assertIsInt($repetitions);
        $this->assertGreaterThanOrEqual(1, $repetitions);
        $this->assertLessThanOrEqual(100, $repetitions);

        for ($iteration = 1; $iteration <= $repetitions; $iteration++) {
            $this->seedIteration();
            $input = new InputStream();
            $holder = $this->worker('hold');
            $holder->setInput($input);
            $contender = $this->worker($operation);
            $context = $operation.' iteration '.$iteration;

            try {
                $holder->start();
                $this->awaitSignal($holder, 'READY', $context);
                $this->assertTrue(SandboxStatus::firstOrFail()->isFree(), $context.' must not see an uncommitted lock');
                $contender->start();
                $this->awaitSignal($contender, 'ATTEMPT', $context);
                $input->write("release\n");
                $input->close();
                $holder->wait();
                $contender->wait();

                $this->assertSame(0, $holder->getExitCode(), $context.' holder: '.$holder->getErrorOutput());
                $this->assertSame(0, $contender->getExitCode(), $context.' contender: '.$contender->getErrorOutput());
                $this->assertSame(['result' => 'ok'], $this->workerResult($holder), $context);
                $expected = $operation === 'open'
                    ? ['result' => 'sandbox_exception', 'code' => SandboxException::CODE_SANDBOX_LOCKED]
                    : ['result' => 'ok'];
                $this->assertSame($expected, $this->workerResult($contender), $context);
                $this->assertSame($expectedState, SandboxStatus::firstOrFail()->status, $context);
                $this->assertSame('1', (string) SandboxStatus::firstOrFail()->user_id, $context);
                $this->assertSame($active, DB::table('concurrent_items')->value('name'), $context);
                $this->assertSame($draft, DB::table('concurrent_items_sb')->value('name'), $context);
            } finally {
                $input->close();
                $holder->stop(0);
                $contender->stop(0);
            }
        }
    }

    private function seedIteration(): void
    {
        SandboxStatus::query()->update([
            'status'         => State::Free,
            'user_id'        => null,
            'last_operation' => null,
            'change_id'      => 0,
        ]);
        foreach (['concurrent_items', 'concurrent_items_sb'] as $table) {
            DB::table($table)->delete();
            DB::table($table)->insert(['id' => 1, 'name' => 'active']);
        }
    }

    private function worker(string $operation): Process
    {
        return new Process(
            [PHP_BINARY, dirname(__DIR__).'/Fixtures/concurrency_worker.php', $operation],
            dirname(__DIR__, 2),
            ['SANDBOX_WORKER_CONNECTION' => json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR)],
            timeout: 20,
        );
    }

    private function awaitSignal(Process $process, string $signal, string $context): void
    {
        $output = '';
        $ready = false;

        try {
            foreach ($process->getIterator(Process::ITER_KEEP_OUTPUT) as $type => $chunk) {
                if ($type === Process::OUT) {
                    $output .= $chunk;
                }

                if (str_contains($output, $signal."\n")) {
                    $ready = true;
                    break;
                }
            }
        } catch (ProcessTimedOutException $exception) {
            $this->fail($context.' barrier '.$signal.': '.$exception->getMessage()."\n".$this->processDiagnostics($process));
        }
        $this->assertTrue($ready, $context.' barrier '.$signal.': '.$this->processDiagnostics($process));
    }

    #[Test]
    public function barrierReadsOutputBufferedBeforeWaiting(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "READY\n");'], timeout: 20);
        $process->mustRun();

        $this->assertSame("READY\n", $process->getOutput());
        $this->awaitSignal($process, 'READY', 'buffered output');
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit_code='.var_export($process->getExitCode(), true)
            .'; command='.$process->getCommandLine()
            ."\nstdout:\n".$process->getOutput()
            ."\nstderr:\n".$process->getErrorOutput();
    }

    private function workerResult(Process $process): array
    {
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_starts_with($line, 'RESULT ')) {
                return json_decode(substr($line, 7), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        $this->fail('Worker did not report a result: '.$process->getOutput());
    }
}
