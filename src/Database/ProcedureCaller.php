<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Database;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final class ProcedureCaller
{
    /**
     * Invoke a PostgreSQL void function, or a MySQL/Oracle procedure.
     *
     * @param list<int|float|string|bool|null> $parameters
     */
    public function call(ConnectionInterface $connection, string $procedure, array $parameters = []): void
    {
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $procedure)) {
            throw new InvalidArgumentException('Invalid procedure identifier.');
        }

        if (! array_is_list($parameters)) {
            throw new InvalidArgumentException('Procedure parameters must be positional.');
        }

        $arguments = implode(', ', array_fill(0, count($parameters), '?'));
        $statement = match ($connection->getDriverName()) {
            'pgsql'                     => "SELECT {$procedure}({$arguments})",
            'mysql'                     => "CALL {$procedure}({$arguments})",
            'oracle', 'oci8'            => "BEGIN {$procedure}({$arguments}); END;",
            default                     => throw new InvalidArgumentException('This database does not support procedure calls.'),
        };

        $connection->statement($statement, $parameters);
    }
}
