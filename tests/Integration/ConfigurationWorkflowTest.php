<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Events\ResolvingSandboxModels;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Http\Middleware\SandboxMiddleware;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Cosmira\Sandbox\Tests\TestUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ConfigurationWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ConfigurationModelStub::useActiveTable();

        Schema::dropIfExists('configuration_items_sb');
        Schema::dropIfExists('configuration_items');
        Schema::create('configuration_items', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('value');
            $table->timestamps();
        });
        Schema::create('configuration_items_sb', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('value');
            $table->timestamps();
        });

        DB::table('configuration_items')->insert([
            'id'         => 1,
            'name'       => 'site_name',
            'value'      => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Event::listen(ResolvingSandboxModels::class, function (
            ResolvingSandboxModels $event,
        ): void {
            $event->models(ConfigurationModelStub::class);
        });
    }

    #[Test]
    public function ownerCanEditConfigurationInSandboxWhileOthersAreRejected(): void
    {
        $owner = $this->createConfigurationUser(1);
        $other = $this->createConfigurationUser(2);
        $middleware = new SandboxMiddleware();

        app(Sandbox::class)->open($owner);
        ConfigurationModelStub::syncIntoSandbox();

        $ownerRequest = Request::create('/configuration/1', 'PATCH');
        $ownerRequest->setUserResolver(fn () => $owner);

        $middleware->handle($ownerRequest, function (): string {
            ConfigurationModelStub::query()
                ->whereKey(1)
                ->update(['value' => 'Draft']);

            return 'updated';
        });

        $this->assertSame('Active', DB::table('configuration_items')->value('value'));
        $this->assertSame('Draft', DB::table('configuration_items_sb')->value('value'));

        $otherRequest = Request::create('/configuration/1', 'PATCH');
        $otherRequest->setUserResolver(fn () => $other);

        try {
            $middleware->handle($otherRequest, function (): string {
                ConfigurationModelStub::query()
                    ->whereKey(1)
                    ->update(['value' => 'Other Draft']);

                return 'updated';
            });
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('Draft', DB::table('configuration_items_sb')->value('value'));
            $this->assertSame('Active', DB::table('configuration_items')->value('value'));

            return;
        }

        $this->fail('Other user was allowed to edit locked configuration.');
    }

    /**
     * Create a test user with a stable, unique email.
     */
    private function createConfigurationUser(int $id): Model
    {
        $user = new TestUser();
        $user->id = $id;
        $user->name = 'Configuration User '.$id;
        $user->email = 'configuration'.$id.'@example.com';
        $user->password = bcrypt('password');
        $user->save();

        return $user;
    }
}

class ConfigurationModelStub extends Model
{
    use HasSandbox;

    protected $table = 'configuration_items';

    protected $guarded = [];

    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return 'updated_at';
    }
}
