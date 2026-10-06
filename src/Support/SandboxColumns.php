<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Illuminate\Database\Schema\Builder;

/**
 * Discovers columns that can be written during automatic sandbox synchronization.
 */
final class SandboxColumns
{
    /**
     * Exclude generated values and use visible schema metadata instead of driver column listings.
     *
     * @return list<string>
     */
    public static function writable(Builder $schema, string $table): array
    {
        $columns = array_filter(
            $schema->getColumns($table),
            static fn (array $column): bool => ($column['generation'] ?? null) === null,
        );

        return array_column($columns, 'name');
    }
}
