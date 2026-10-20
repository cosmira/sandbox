<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Relations;

use Closure;
use Cosmira\Sandbox\Eloquent\Context;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Select the registered pivot table before Laravel builds joins and constraints.
 *
 * @phpstan-type Assignments array<int|string, int|string|array<string, mixed>>
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel>
 */
class SandboxBelongsToMany extends BelongsToMany
{
    /**
     * Construct a pivot relation using its parent’s selected table context.
     *
     * @param Builder<TRelatedModel> $sandboxQuery  The related query before pivot resolution.
     * @param TDeclaringModel        $sandboxParent The parent selecting active or draft tables.
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
     * Apply the selected relation's delta to both copies without opening a draft.
     *
     * Return copies affected by valid assignments or the removal delta, including
     * keyed missing parents for removals. Do not touch parent or related models.
     * A payload factory runs exactly once, after both parents are locked.
     *
     * @param Assignments|(Closure(): Assignments) $ids
     *
     * @return list<TDeclaringModel>
     */
    public function syncAcrossCopies(array|Closure $ids): array
    {
        $this->ensureAcrossCopiesRelation();

        return $this->parent->getConnection()->transaction(function () use ($ids): array {
            $copies = $this->lockAcrossCopies();
            $input = value($ids);
            $this->ensureSupportedIds($input);
            $records = $this->formatRecordsList($input);
            $this->ensureAcrossCopiesRecords($records);
            $source = clone $this;
            $column = $this->related->qualifyColumn($this->relatedKey);
            $current = $source->getQuery()->toBase()->select($column)->pluck($column)->all();
            $removed = array_values(array_diff($current, array_keys($records)));
            $affected = [];

            foreach ($copies as $copy) {
                $relation = $this->relationForCopy($copy);
                $valid = $copy->exists ? $relation->existingCopyRecords($records) : [];
                $relation->writeCopyRecords($valid);
                $relation->detach($removed, false);

                if ($valid === [] && $removed === []) {
                    continue;
                }

                $affected[] = $copy;
            }

            return $affected;
        });
    }

    /**
     * Require a declared, registered relation on one connection before any writes.
     */
    private function ensureAcrossCopiesRelation(): void
    {
        $this->modelForCopy($this->parent, false);
        $this->modelForCopy($this->related, false);
        $connection = $this->parent->getConnection();

        if ($this->related->getConnection() !== $connection
            || Context::resolveTable($this->table, $connection, false) === null) {
            throw new \InvalidArgumentException(
                'syncAcrossCopies requires a registered pivot on the parent connection.',
            );
        }

        Context::ensureTableSupportsSync($this->table);

        $customQuery = $this->hasCustomSourceQuery();

        if ($customQuery || $this->pivotValues !== []) {
            throw new \InvalidArgumentException(
                'syncAcrossCopies requires an unfiltered relation with its default query shape.',
            );
        }
    }

    /**
     * Reject source projections and constraints that cannot be mirrored safely.
     */
    private function hasCustomSourceQuery(): bool
    {
        $expected = clone $this;
        $expected->query = $this->related->newQuery();
        $expected->addConstraints();
        $expected->query->reorder();
        $actual = clone $this->query;
        $actual->reorder();
        $sameSql = $actual->toSql() === $expected->query->toSql();
        $sameBindings = $actual->getBindings() === $expected->query->getBindings();

        return ! $sameSql || ! $sameBindings;
    }

    /**
     * Validate raw ID lists before PHP can coerce their values into array keys.
     *
     * @param array<array-key, mixed> $ids
     */
    private function ensureSupportedIds(array $ids): void
    {
        foreach ($ids as $value) {
            $supported = is_int($value) || is_string($value) || is_array($value);

            if (! $supported) {
                throw new \InvalidArgumentException(
                    'Assignments require integer/string IDs or pivot attribute maps.',
                );
            }
        }
    }

    /**
     * Reject pivot identity overrides rather than writing another parent's rows.
     *
     * @param array<int|string, array<string, mixed>> $records
     */
    private function ensureAcrossCopiesRecords(array $records): void
    {
        foreach ($records as $attributes) {
            if (array_key_exists($this->foreignPivotKey, $attributes)
                || array_key_exists($this->relatedPivotKey, $attributes)) {
                throw new \InvalidArgumentException(
                    'Pivot attributes cannot override relation keys.',
                );
            }
        }
    }

    /**
     * Create an independent model pinned to the requested physical table.
     *
     * @template TCopy of Model
     *
     * @param TCopy $model
     *
     * @return TCopy
     */
    private function modelForCopy(Model $model, bool $draft): Model
    {
        if (! method_exists($model, 'getActiveTable')
            || ! method_exists($model, 'getSandboxTable')) {
            throw new \InvalidArgumentException(
                'syncAcrossCopies requires HasSandbox on both models.',
            );
        }

        $table = $draft ? $model->getSandboxTable() : $model->getActiveTable();

        return $model->newInstance()->setTable($table);
    }

    /**
     * Lock active before draft and retain identity when a parent copy is absent.
     *
     * @return list<TDeclaringModel>
     */
    private function lockAcrossCopies(): array
    {
        $copies = [];

        foreach ([false, true] as $draft) {
            $model = $this->modelForCopy($this->parent, $draft);
            $copy = $model->newQuery()->whereKey($this->parent->getKey())->lockForUpdate()->first();
            $copies[] = $copy ?? $model
                ->setAttribute($model->getKeyName(), $this->parent->getKey())
                ->setAttribute($this->parentKey, $this->parent->getAttribute($this->parentKey));
        }

        return $copies;
    }

    /**
     * Preserve relation metadata while pinning its write query to another copy.
     *
     * @param TDeclaringModel $parent
     *
     * @return self<TRelatedModel, TDeclaringModel>
     */
    private function relationForCopy(Model $parent): self
    {
        $draft = $parent->getTable() !== $this->modelForCopy($parent, false)->getTable();
        $relation = clone $this;
        $relation->parent = $parent;
        $relation->related = $this->modelForCopy($this->related, $draft);
        $relation->query = $relation->related->newQuery();
        $relation->table = Context::resolveTable($this->table, $parent->getConnection(), $draft)
            ?? throw new \InvalidArgumentException('The pivot must remain registered during sync.');

        return $relation;
    }

    /**
     * Lock raw related keys without applying model casts or accessors.
     *
     * @param array<int|string, array<string, mixed>> $records
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function existingCopyRecords(array $records): array
    {
        if ($records === []) {
            return [];
        }

        $column = $this->related->qualifyColumn($this->relatedKey);
        $query = $this->related->newQuery();
        $query->whereIn($column, array_map(strval(...), array_keys($records)))
            ->orderBy($column)->lockForUpdate();
        $keys = $query->toBase()->select($column)->pluck($column)->map(strval(...))->all();

        return array_filter(
            $records,
            static fn (int|string $id): bool => in_array((string) $id, $keys, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Batch ordinary uniform payloads, retaining Laravel's custom pivot behavior.
     *
     * @param array<int|string, array<string, mixed>> $records
     */
    private function writeCopyRecords(array $records): void
    {
        if ($records === []) {
            return;
        }

        if (! $this->canBatchCopyRecords($records)) {
            $this->writeIndividualCopyRecords($records);

            return;
        }

        $columns = array_keys(reset($records));

        $updatedAt = $this->updatedAt();

        if ($this->hasPivotColumn($updatedAt)) {
            $columns[] = $updatedAt;
        }

        $this->newPivotQuery()->upsert(
            $this->formatAttachRecords($records, []),
            [$this->foreignPivotKey, $this->relatedPivotKey],
            array_values(array_unique($columns)),
        );
    }

    /**
     * Require matching nonempty column sets for a plain pivot batch.
     *
     * @param array<int|string, array<string, mixed>> $records
     */
    private function canBatchCopyRecords(array $records): bool
    {
        $columns = array_keys(reset($records));
        sort($columns);

        if ($this->using !== null || $columns === []) {
            return false;
        }

        foreach ($records as $attributes) {
            $keys = array_keys($attributes);
            sort($keys);

            if ($keys !== $columns) {
                return false;
            }
        }

        return $this->hasBatchCompatibleIndexes();
    }

    /**
     * Batch only when the pivot pair is the primary and sole unique identity.
     */
    private function hasBatchCompatibleIndexes(): bool
    {
        $indexes = $this->parent->getConnection()->getSchemaBuilder()->getIndexes($this->table);
        $unique = array_values(array_filter(
            $indexes,
            static fn (array $index): bool => $index['unique'],
        ));

        if (count($unique) !== 1) {
            return false;
        }

        $index = $unique[0];
        $columns = [$this->foreignPivotKey, $this->relatedPivotKey];
        $keys = $index['columns'];
        sort($columns);
        sort($keys);

        return $index['primary'] && $keys === $columns;
    }

    /**
     * Preserve casts, events and heterogeneous attributes without loose ID matching.
     *
     * @param array<int|string, array<string, mixed>> $records
     */
    private function writeIndividualCopyRecords(array $records): void
    {
        $current = $this->newPivotQuery()->pluck($this->relatedPivotKey)->map(strval(...))->all();

        foreach ($records as $id => $attributes) {
            if (! in_array((string) $id, $current, true)) {
                $this->attach($id, $attributes, false);

                continue;
            }

            if ($attributes === []) {
                continue;
            }

            $this->updateExistingPivot($id, $attributes, false);
        }
    }

    /**
     * Select the registered pivot table for the parent copy.
     */
    protected function resolveTableName(mixed $table): string
    {
        $resolvedTable = parent::resolveTableName($table);
        $draft = method_exists($this->sandboxParent, 'isUsingSandboxTable')
            && $this->sandboxParent->isUsingSandboxTable();
        $connection = $this->sandboxQuery->getConnection();
        $resolved = Context::resolveTable(
            $resolvedTable, $connection,
            $draft,
        );
        if ($resolved === null) {
            return $resolvedTable;
        }

        return $resolved;
    }
}
