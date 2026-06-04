<?php

declare(strict_types=1);

namespace Packages\Sandbox;

use function class_exists;

use Illuminate\Support\ServiceProvider;
use Packages\Sandbox\Commands\BenchmarkSyncCommand;
use Packages\Sandbox\Commands\CloseSandboxCommand;
use Packages\Sandbox\Commands\OpenSandboxCommand;
use Packages\Sandbox\Commands\StatusSandboxCommand;

class SandboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sandbox.php', 'sandbox');

        $this->app->singleton(Sandbox::class);
    }

    public function boot(): void
    {
        Sandbox::macro('for', function (int|string $userId) {
            return new SandboxBuilder($userId);
        });

        Sandbox::macro('me', function () {
            $user = auth()->user();
            if (! $user) {
                throw new \RuntimeException('No authenticated user found. Use Sandbox::for($userId) instead of Sandbox::me().');
            }

            return new SandboxBuilder($user->getAuthIdentifier());
        });

        if ($this->app->runningInConsole()) {
            $commands = [
                OpenSandboxCommand::class,
                CloseSandboxCommand::class,
                StatusSandboxCommand::class,
            ];

            if (class_exists('DragonCode\Benchmark\Benchmark')) {
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
