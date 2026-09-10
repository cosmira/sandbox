<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Relations;

use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Select the registered pivot table before Laravel builds joins and constraints. */
class SandboxBelongsToMany extends BelongsToMany
{
    public function __construct(
        private readonly Builder $sandboxQuery,
        private readonly Model $sandboxParent,
        mixed $table,
        mixed $foreignPivotKey,
        mixed $relatedPivotKey,
        mixed $parentKey,
        mixed $relatedKey,
        mixed $relationName = null,
    ) {
        parent::__construct(
            $sandboxQuery, $sandboxParent, $table, $foreignPivotKey,
            $relatedPivotKey, $parentKey, $relatedKey, $relationName,
        );
    }

    protected function resolveTableName(mixed $table): string
    {
        $table = parent::resolveTableName($table);
        $draft = $this->sandboxParent->isUsingSandboxTable();
        $resolved = app(SandboxModelRegistry::class)->resolveTable(
            $table, $this->sandboxQuery->getConnection(), $draft,
        );
        if ($resolved === null) {
            return $table;
        }

        $related = $this->sandboxQuery->getModel();
        if (method_exists($related, 'getSandboxTable') && method_exists($related, 'getActiveTable')) {
            $relatedTable = $draft ? $related->getSandboxTable() : $related->getActiveTable();
            $related->setTable($relatedTable);
            $this->sandboxQuery->from($relatedTable);
        }

        return $resolved;
    }
}
