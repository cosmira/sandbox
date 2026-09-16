<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Relations;

use Cosmira\Sandbox\Eloquent\Context;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Select the registered pivot table before Laravel builds joins and constraints.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel>
 */
class SandboxBelongsToMany extends BelongsToMany
{
    /**
     * @param Builder<TRelatedModel> $sandboxQuery
     * @param TDeclaringModel        $sandboxParent
     */
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

    /**
     * Select a registered pivot table and align the related model with its parent.
     */
    protected function resolveTableName(mixed $table): string
    {
        $table = parent::resolveTableName($table);
        $draft = method_exists($this->sandboxParent, 'isUsingSandboxTable')
            && $this->sandboxParent->isUsingSandboxTable();
        $connection = $this->sandboxQuery->getConnection();
        $resolved = Context::resolveTable(
            $table, $connection,
            $draft,
        );
        if ($resolved === null) {
            return $table;
        }

        $related = $this->sandboxQuery->getModel();
        if (method_exists($related, 'getSandboxTable')
            && method_exists($related, 'getActiveTable')) {
            $relatedTable = $draft ? $related->getSandboxTable() : $related->getActiveTable();
            $related->setTable($relatedTable);
            $this->sandboxQuery->from($relatedTable);
        }

        return $resolved;
    }
}
