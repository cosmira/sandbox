<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use function class_exists;

use Cosmira\Sandbox\Commands\BenchmarkSyncCommand;
use Cosmira\Sandbox\Commands\CloseSandboxCommand;
use Cosmira\Sandbox\Commands\OpenSandboxCommand;
use Cosmira\Sandbox\Commands\StatusSandboxCommand;
use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Events\ResolvingSandboxModels;
use Cosmira\Sandbox\Events\SandboxClosed;
use Cosmira\Sandbox\Http\Middleware\SandboxMiddleware;
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

        Event::listen(SandboxClosed::class, function (SandboxClosed $event): void {
            if ($event->result !== SandboxOperation::Save) {
                ResolvingSandboxModels::restoreActiveTables();
            }
        });

        Sandbox::macro('for', function (int|string $userId) {
            return new SandboxBuilder($userId);
        });

        Sandbox::macro('me', function () {
            $user = auth()->user();
            if (! $user) {
                throw new \RuntimeException(
                    'No authenticated user found. '
                    .'Use Sandbox::for($userId) instead of Sandbox::me().',
                );
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
