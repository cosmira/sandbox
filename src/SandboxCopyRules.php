<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Closure;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/** Host-owned row and column selection for copying into an existing shared draft. */
final readonly class SandboxCopyRules
{
    /**
     * Update filters receive a join with aliases `source` and `target`.
     * Insert and delete filters receive a query over the respective unaliased table.
     *
     * Null update columns copy all columns; [] preserves matched rows.
     *
     * @param list<string>|null            $updateColumns
     * @param Closure(Builder): mixed|null $inserts
     * @param Closure(Builder): mixed|null $updates
     * @param Closure(Builder): mixed|null $deletes
     */
    public function __construct(
        public ?array $updateColumns = null,
        public ?Closure $inserts = null,
        public ?Closure $updates = null,
        public ?Closure $deletes = null,
    ) {
        $this->validateColumns($updateColumns ?? []);
    }

    /** @param array<array-key, mixed> $columns */
    private function validateColumns(array $columns): void
    {
        $uniqueColumns = array_unique($columns);
        $hasDuplicates = count($uniqueColumns) !== count($columns);
        if (! array_is_list($columns) || $hasDuplicates) {
            throw new InvalidArgumentException(
                'Sandbox update columns must be a list of unique column names.',
            );
        }
        foreach ($columns as $column) {
            if (! is_string($column) || trim($column) === '') {
                throw new InvalidArgumentException(
                    'Sandbox update columns must be nonempty strings.',
                );
            }
        }
    }
}
