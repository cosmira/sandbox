<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests;

use Cosmira\Sandbox\SandboxServiceProvider;
use Cosmira\Sandbox\Testing\SandboxTestHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RuntimeException;

class TestUser extends Model implements Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    public $timestamps = true;

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->getKey();
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return $this->remember_token ?? null;
    }

    public function setRememberToken($value): void
    {
        $this->remember_token = $value;
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}

abstract class TestCase extends BaseTestCase
{
    use SandboxTestHelpers;

    protected function getPackageProviders($app): array
    {
        return [
            SandboxServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $driver = getenv('SANDBOX_TEST_DRIVER') ?: 'sqlite';
        $database = getenv('SANDBOX_TEST_DATABASE') ?: ':memory:';
        if (! in_array($driver, ['sqlite', 'pgsql', 'mysql', 'oracle'], true)) {
            throw new RuntimeException('Unsupported sandbox contract database driver.');
        }
        if ($driver !== 'sqlite' && (getenv('SANDBOX_TEST_ALLOW_DESTRUCTIVE') !== '1'
            || ! preg_match('/^sandbox_contract(?:_[a-z0-9]+)?$/', $database))) {
            throw new RuntimeException('Native tests require a dedicated sandbox_contract database and explicit destructive-test consent.');
        }
        if ($driver === 'sqlite' && $database !== ':memory:') {
            throw new RuntimeException('Use the isolated concurrency fixture for file-backed SQLite tests.');
        }
        $app['config']->set('database.connections.testing', [
            'driver'                  => $driver,
            'database'                => $database,
            'host'                    => getenv('SANDBOX_TEST_HOST') ?: '127.0.0.1',
            'port'                    => getenv('SANDBOX_TEST_PORT') ?: null,
            'username'                => getenv('SANDBOX_TEST_USERNAME') ?: 'sandbox_test',
            'password'                => getenv('SANDBOX_TEST_PASSWORD') ?: '',
            'charset'                 => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation'               => 'utf8mb4_unicode_ci',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('auth.providers.users.model', TestUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function createUser(?int $id = null): TestUser
    {
        $user = new TestUser();
        $user->name = 'Test User';
        $user->email = 'test@example.com';
        $user->password = bcrypt('password');
        if ($id !== null) {
            $user->id = $id;
        }
        $user->save();

        return $user;
    }
}
