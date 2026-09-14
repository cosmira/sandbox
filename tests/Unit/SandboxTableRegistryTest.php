<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Tests\Unit;

use Cosmira\Sandbox\Backends\EloquentSandboxBackend;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\HasSandbox;
use Cosmira\Sandbox\SandboxTable;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Cosmira\Sandbox\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class SandboxTableRegistryTest extends TestCase
{
    #[Test]
    public function resolvesEachRegisteredTableAndLeavesUnknownTablesAlone(): void
    {
        $registry = new SandboxModelRegistry();
        $connection = DB::connection();
        $registry->registerTables($connection, new SandboxTable('first', ['id']), new SandboxTable('second', ['id']));
        foreach (['first', 'second'] as $name) {
            $this->assertSame($name.'_sb', $registry->resolveTable($name, $connection, true));
            $this->assertSame($name, $registry->resolveTable($name.'_sb', $connection, false));
        }
        $this->assertNull($registry->resolveTable('unknown', $connection, true));
    }

    #[Test]
    #[DataProvider('conflictingNames')]
    public function overlappingNamesReportTheConflictingTable(string $active, string $draft): void
    {
        $registry = new SandboxModelRegistry();
        $registry->registerTables(DB::connection(), new SandboxTable('first', ['id']));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting sandbox table registration: '.$active);
        $registry->registerTables(DB::connection(), new SandboxTable($active, ['id'], $draft));
    }

    public static function conflictingNames(): iterable
    {
        yield ['first', 'other_draft'];
        yield ['first_sb', 'other_draft'];
        yield ['other', 'first'];
        yield ['other', 'first_sb'];
    }

    #[Test]
    #[DataProvider('connectionOperations')]
    public function registeredTablesRejectAnotherConnection(string $operation): void
    {
        $registry = new SandboxModelRegistry();
        $connection = DB::connection();
        $other = $this->createStub(ConnectionInterface::class);
        $registry->registerTables($connection, new SandboxTable('first', ['id']));
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Registered tables must use the sandbox context connection.');
        match ($operation) {
            'register' => $registry->registerTables($other, new SandboxTable('second', ['id'])),
            'resolve'  => $registry->resolveTable('first', $other, true),
            'context'  => $registry->usingTables(true, fn () => $this->fail('Wrong connection accepted.'), $other),
            'nested'   => $registry->usingTables(true, fn () => $registry->usingTables(false, fn () => $this->fail('Wrong nested connection accepted.'), $other), $connection),
        };
    }

    public static function connectionOperations(): iterable
    {
        foreach (['register', 'resolve', 'context', 'nested'] as $operation) {
            yield [$operation];
        }
    }

    #[Test]
    public function backendRejectsTablesFromAnotherConnectionBeforeOpening(): void
    {
        $registry = new SandboxModelRegistry();
        $registry->registerTables($this->createStub(ConnectionInterface::class), new SandboxTable('first', ['id']));
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('Registered tables must use the sandbox context connection.');
        (new EloquentSandboxBackend($registry))->open(1);
    }

    #[Test]
    #[DataProvider('registrationOrders')]
    public function modelsAndTablesCannotShareOnlyTheirActiveName(bool $modelFirst): void
    {
        $registry = new SandboxModelRegistry();
        $table = new SandboxTable('registry_items', ['id'], 'different_draft');
        if ($modelFirst) {
            $registry->register(RegistryTableModel::class);
        } else {
            $registry->registerTables(DB::connection(), $table);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A sandbox table is already represented by a model: registry_items');
        if ($modelFirst) {
            $registry->registerTables(DB::connection(), $table);
        } else {
            $registry->register(RegistryTableModel::class);
        }
    }

    #[Test]
    #[DataProvider('registrationOrders')]
    public function modelsCannotEnterAContextOnAnotherConnection(bool $modelFirst): void
    {
        $registry = new SandboxModelRegistry();
        if ($modelFirst) {
            $registry->register(RegistryTableModel::class);
        }
        $this->expectException(SandboxException::class);
        $this->expectExceptionMessage('must use the sandbox context connection.');
        $registry->usingTables(true, function () use ($registry, $modelFirst): void {
            if ($modelFirst) {
                $this->fail('Model entered a context on another connection.');
            }
            $registry->register(RegistryTableModel::class);
        }, $this->createStub(ConnectionInterface::class));
    }

    public static function registrationOrders(): iterable
    {
        yield [true];
        yield [false];
    }
}

class RegistryTableModel extends Model
{
    use HasSandbox;

    protected $table = 'registry_items';
}
