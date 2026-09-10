<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\SandboxTable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SandboxTableTest extends TestCase
{
    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function invalidDefinitionsAreRejected(string $table, array $keys, ?string $draft): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SandboxTable($table, $keys, $draft);
    }

    public static function invalidDefinitions(): iterable
    {
        yield 'empty table' => ['', ['id'], null];
        yield 'empty draft' => ['links', ['id'], ' '];
        yield 'same table' => ['links', ['id'], 'links'];
        yield 'missing keys' => ['links', [], null];
        yield 'empty key' => ['links', [''], null];
        yield 'non-string key' => ['links', [1], null];
        yield 'duplicate key' => ['links', ['id', 'id'], null];
        yield 'associative keys' => ['links', ['first' => 'id'], null];
    }

    #[Test]
    #[DataProvider('invalidTrees')]
    public function invalidTreeDefinitionsAreRejected(array $keys, string $parent): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SandboxTable('nodes', $keys, parentColumn: $parent);
    }

    public static function invalidTrees(): iterable
    {
        yield 'empty parent' => [['id'], ''];
        yield 'composite key' => [['id', 'type'], 'parent_id'];
        yield 'same key and parent' => [['id'], 'id'];
    }
}
