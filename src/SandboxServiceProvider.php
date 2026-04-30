<?php

declare(strict_types=1);

namespace Packages\Sandbox;

use Illuminate\Support\ServiceProvider;
use Packages\Sandbox\Commands\CloseSandboxCommand;
use Packages\Sandbox\Commands\OpenSandboxCommand;
use Packages\Sandbox\Commands\StatusSandboxCommand;
use Packages\Sandbox\Contracts\SandboxSyncRunnerInterface;
use function class_exists;

class SandboxServiceProvider extends ServiceProvider
{
    /**
     * Зарегистрировать сервисы пакета.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sandbox.php', 'sandbox');

        $this->app->singleton(Sandbox::class);
        $this->app->bind(SandboxSyncRunnerInterface::class, NullSandboxSyncRunner::class);
    }

    /**
     * Загрузить сервисы пакета.
     *
     * @return void
     */
    public function boot(): void
    {
        // Регистрировать macroable методы
        Sandbox::macro('for', function (int|string $userId) {
            return new SandboxBuilder($userId);
        });

        // Автоматическое определение текущего пользователя
        Sandbox::macro('me', function () {
            $user = auth()->user();
            if (! $user) {
                throw new \RuntimeException('No authenticated user found. Use Sandbox::for($userId) instead of Sandbox::me().');
            }

            return new SandboxBuilder($user->getAuthIdentifier());
        });

        if ($this->app->runningInConsole()) {
            // Регистрировать команды
            $commands = [
                OpenSandboxCommand::class,
                CloseSandboxCommand::class,
                StatusSandboxCommand::class,
            ];

            // Добавить benchmark команду только если доступен dev пакет
            if (class_exists('DragonCode\Benchmark\Benchmark')) {
                $commands[] = \Packages\Sandbox\Commands\BenchmarkSyncCommand::class;
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
