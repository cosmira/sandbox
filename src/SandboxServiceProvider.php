<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use function class_exists;

use Cosmira\Sandbox\Commands\BenchmarkSyncCommand;
use Cosmira\Sandbox\Commands\CommitSandboxCommand;
use Cosmira\Sandbox\Commands\OpenSandboxCommand;
use Cosmira\Sandbox\Commands\RollbackSandboxCommand;
use Cosmira\Sandbox\Commands\SaveSandboxCommand;
use Cosmira\Sandbox\Commands\StatusSandboxCommand;
use Cosmira\Sandbox\Events\SandboxCommitted;
use Cosmira\Sandbox\Events\SandboxRolledBack;
use Cosmira\Sandbox\Http\Middleware\SandboxMiddleware;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use DragonCode\Benchmark\Benchmark;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Bootstrap the sandbox package services.
 */
class SandboxServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sandbox.php', 'sandbox');

        $this->app->scoped(Eloquent\TableContext::class);
        $this->app->singleton(SandboxModelRegistry::class);
        $this->app->singleton(
            Contracts\SandboxBackend::class,
            Backends\EloquentSandboxBackend::class,
        );
        $this->app->singleton(Sandbox::class);
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        $this->app['router']->aliasMiddleware(
            'sandbox',
            SandboxMiddleware::class,
        );

        $registry = $this->app->make(SandboxModelRegistry::class);
        Event::listen(SandboxCommitted::class, fn () => $registry->restoreActiveTables());
        Event::listen(SandboxRolledBack::class, fn () => $registry->restoreActiveTables());

        if ($this->app->runningInConsole()) {
            $commands = [
                OpenSandboxCommand::class,
                CommitSandboxCommand::class,
                RollbackSandboxCommand::class,
                SaveSandboxCommand::class,
                StatusSandboxCommand::class,
            ];

            if (class_exists(Benchmark::class)) {
                $commands[] = BenchmarkSyncCommand::class;
            }

            $this->commands($commands);

            $this->publishes([
                __DIR__.'/../config/sandbox.php' => config_path('sandbox.php'),
            ], 'sandbox-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'sandbox-migrations');
        }
    }
}
