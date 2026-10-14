<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Support\SandboxColumns;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SandboxColumnsTest extends TestCase
{
    #[Test]
    public function oracleDiscoveryReadsOnlyVisibleWritableColumnNames(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('oracle');
        $connection->method('getTablePrefix')->willReturn('tm_');
        $connection->method('getConfig')->with('username')->willReturn('owner');
        $connection->expects($this->once())->method('selectFromWriteConnection')
            ->with(
                'select lower(column_name) as name from all_tab_cols '
                .'where owner = ? and table_name = ? '
                ."and hidden_column = 'NO' and virtual_column = 'NO' order by column_id",
                ['OTHER', 'TM_ITEMS'],
            )->willReturn([(object) ['name' => 'id'], (object) ['name' => 'value']]);
        $schema = $this->createMock(Builder::class);
        $schema->method('getConnection')->willReturn($connection);
        $schema->method('parseSchemaAndTable')->with('other.items')->willReturn(['other', 'items']);
        $schema->expects($this->never())->method('getColumns');

        $this->assertSame(['id', 'value'], SandboxColumns::writable($schema, 'other.items'));
    }

    #[Test]
    public function oracleDiscoveryDoesNotRetainColumnsBetweenOperations(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('oracle');
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getConfig')->with('username')->willReturn('owner');
        $connection->expects($this->exactly(2))->method('selectFromWriteConnection')
            ->with($this->anything(), ['OWNER', 'ITEMS'])
            ->willReturnOnConsecutiveCalls(
                [(object) ['name' => 'id']],
                [(object) ['name' => 'id'], (object) ['name' => 'added']],
            );
        $schema = $this->createMock(Builder::class);
        $schema->method('getConnection')->willReturn($connection);
        $schema->method('parseSchemaAndTable')->willReturn([null, 'items']);
        $schema->expects($this->never())->method('getColumns');

        $this->assertSame(['id'], SandboxColumns::writable($schema, 'items'));
        $this->assertSame(['id', 'added'], SandboxColumns::writable($schema, 'items'));
    }
}
