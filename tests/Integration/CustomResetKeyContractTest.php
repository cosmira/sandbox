<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Integration;

use Cosmira\Sandbox\Enums\SandboxStatus as State;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class CustomResetKeyContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['reset_key_items', 'reset_key_items_sb'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('code')->unique();
                $table->string('name');
            });
        }
        SandboxStatus::query()->update(['status' => State::Locked, 'user_id' => 1]);
        DB::table('reset_key_items')->insert([
            ['id' => 1, 'code' => 'alpha', 'name' => 'Active'],
            ['id' => 2, 'code' => '1', 'name' => 'Unrelated active'],
        ]);
        DB::table('reset_key_items_sb')->insert([
            ['id' => 1, 'code' => 'alpha', 'name' => 'Draft'],
            ['id' => 2, 'code' => '1', 'name' => 'Unrelated draft'],
        ]);
    }

    public static function identifiers(): array
    {
        return [
            'with eloquent key'    => [['id' => 1, 'code' => 'alpha']],
            'without eloquent key' => [['code' => 'alpha']],
        ];
    }

    #[Test]
    #[DataProvider('identifiers')]
    public function restoresOnlyTheConfiguredScalarKey(array $attributes): void
    {
        $model = (new CustomResetKeyModel())->forceFill($attributes);

        app(Sandbox::class)->reset(1, $model);

        $this->assertSame('Active', DB::table('reset_key_items_sb')->where('code', 'alpha')->value('name'));
        $this->assertSame('Unrelated draft', DB::table('reset_key_items_sb')->where('code', '1')->value('name'));
    }

    public static function incompleteIdentifiers(): array
    {
        return [
            'missing configured key' => [['id' => 1]],
            'null configured key'    => [['id' => 1, 'code' => null]],
        ];
    }

    #[Test]
    #[DataProvider('incompleteIdentifiers')]
    public function missingConfiguredKeyNeverFallsBackToTheEloquentKey(array $attributes): void
    {
        $before = DB::table('reset_key_items_sb')->orderBy('id')->get()->all();

        app(Sandbox::class)->reset(1, (new CustomResetKeyModel())->forceFill($attributes));

        $this->assertEquals($before, DB::table('reset_key_items_sb')->orderBy('id')->get()->all());
    }

    #[Test]
    public function removesOnlyTheConfiguredOrphanFromTheDraft(): void
    {
        DB::table('reset_key_items')->where('code', 'alpha')->delete();
        $model = (new CustomResetKeyModel())->forceFill(['id' => 1, 'code' => 'alpha']);

        app(Sandbox::class)->reset(1, $model);

        $this->assertFalse(DB::table('reset_key_items_sb')->where('code', 'alpha')->exists());
        $this->assertSame('Unrelated draft', DB::table('reset_key_items_sb')->where('code', '1')->value('name'));
    }
}

class CustomResetKeyModel extends Model
{
    use HasSandbox;

    protected $table = 'reset_key_items';

    public $timestamps = false;

    public function getSandboxPrimaryKey(): string|array
    {
        return 'code';
    }
}
