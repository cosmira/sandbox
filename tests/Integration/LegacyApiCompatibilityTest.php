<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Events\SandboxResolvingModels;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Http\Middleware\SandboxMiddleware;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\SandboxBuilder;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class LegacyApiCompatibilityTest extends TestCase
{
    #[Test]
    public function traitOnlyModelsKeepStaticConfigurationAndCopyBehavior(): void
    {
        foreach (['legacy_items', 'legacy_items_draft'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->string('tenant');
                $table->string('code');
                $table->string('label');
                $table->integer('revision');
                $table->string('foreign_value')->default('untouched');
                $table->primary(['tenant', 'code']);
            });
        }

        try {
            LegacyConfiguredModel::configureSandbox();
            $model = new LegacyConfiguredModel();
            $this->assertSame('legacy_items_draft', $model->getSandboxTable());
            $this->assertSame(['tenant', 'code'], $model->getSandboxPrimaryKey());
            $this->assertTrue(LegacyConfiguredModel::supportsSandboxSync());
            DB::table('legacy_items')->insert([
                'tenant'   => 'acme', 'code' => 'a', 'label' => 'original',
                'revision' => 1, 'foreign_value' => 'external',
            ]);
            $sandbox = new Sandbox();
            $sandbox->models(LegacyConfiguredModel::class);
            $sandbox->open(1);
            $this->assertSame('original', DB::table('legacy_items_draft')->value('label'));
            $this->assertSame('untouched', DB::table('legacy_items_draft')->value('foreign_value'));
            DB::table('legacy_items_draft')->update(['label' => 'edited', 'revision' => 2]);
            $sandbox->commit(1);
            $this->assertSame('edited', DB::table('legacy_items')->value('label'));
            $this->assertSame('external', DB::table('legacy_items')->value('foreign_value'));
        } finally {
            Schema::dropIfExists('legacy_items_draft');
            Schema::dropIfExists('legacy_items');
        }
    }

    #[Test]
    public function optionalConstructorsAndStaticRestorationRemainAvailable(): void
    {
        $sandbox = app(Sandbox::class);
        $this->assertSame($sandbox, (new SandboxBuilder(7))->getSandbox());
        $this->assertInstanceOf(SandboxMiddleware::class, new SandboxMiddleware());
        $event = new SandboxResolvingModels(Request::create('/'));
        $event->models(LegacyConfiguredModel::class);
        $this->assertTrue(LegacyConfiguredModel::isUsingSandbox());
        SandboxResolvingModels::restoreActiveTables();
        $this->assertFalse(LegacyConfiguredModel::isUsingSandbox());
        $this->assertSame([], app(SandboxModelRegistry::class)->all());
    }
}

class LegacyConfiguredModel extends Model
{
    use HasSandbox;

    protected $table = 'legacy_items';
    public $timestamps = false;

    public static function configureSandbox(): void
    {
        static::$sandboxTablePostfix = '_draft';
        static::$sandboxPrimaryKey = ['tenant', 'code'];
        static::$sandboxTrackChangeColumn = 'revision';
        static::$sandboxSyncColumns = ['tenant', 'code', 'label', 'revision'];
    }
}
