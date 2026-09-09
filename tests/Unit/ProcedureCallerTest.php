<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Database\ProcedureCaller;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcedureCallerTest extends TestCase
{
    public static function drivers(): array
    {
        return [
            ['pgsql', 'SELECT app.open_draft(?, ?)'],
            ['mysql', 'CALL app.open_draft(?, ?)'],
            ['oracle', 'BEGIN app.open_draft(?, ?); END;'],
            ['oci8', 'BEGIN app.open_draft(?, ?); END;'],
        ];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function bindsParametersInsteadOfInterpolatingThem(string $driver, string $sql): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn($driver);
        $connection->expects($this->once())->method('statement')
            ->with($sql, [7, "'; DROP TABLE users; --"])->willReturn(true);

        (new ProcedureCaller())->call($connection, 'app.open_draft', [7, "'; DROP TABLE users; --"]);
    }

    #[Test]
    public function rejectsUntrustedIdentifiersBeforeCallingTheDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('pgsql');
        $connection->expects($this->never())->method('statement');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid procedure identifier.');
        (new ProcedureCaller())->call($connection, 'open_draft(); DROP TABLE users');
    }

    #[Test]
    public function rejectsAssociativeBindings(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('pgsql');
        $connection->expects($this->never())->method('statement');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Procedure parameters must be positional.');
        (new ProcedureCaller())->call($connection, 'open_draft', ['user' => 7]);
    }

    #[Test]
    public function failsClosedForUnsupportedDrivers(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('sqlite');
        $connection->expects($this->never())->method('statement');
        $this->expectException(InvalidArgumentException::class);
        (new ProcedureCaller())->call($connection, 'open_draft');
    }
}
