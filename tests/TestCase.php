<?php

declare(strict_types=1);

namespace Packages\Sandbox\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Packages\Sandbox\Testing\SandboxTestHelpers;

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
            \Packages\Sandbox\SandboxServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        // Set the auth model
        $app['config']->set('auth.providers.users.model', TestUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /**
     * Create a test user for authentication.
     */
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
