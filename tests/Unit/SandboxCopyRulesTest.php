<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\SandboxCopyRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SandboxCopyRulesTest extends TestCase
{
    #[Test]
    #[DataProvider('invalidColumns')]
    public function invalidUpdateColumnsAreRejected(array $columns): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SandboxCopyRules(updateColumns: $columns);
    }

    public static function invalidColumns(): iterable
    {
        yield 'duplicate' => [['name', 'name']];
        yield 'associative' => [['column' => 'name']];
        yield 'empty' => [['']];
        yield 'whitespace' => [['  ']];
        yield 'integer' => [[1]];
        yield 'null' => [[null]];
    }
}
