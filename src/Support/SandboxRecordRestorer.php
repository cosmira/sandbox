<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Illuminate\Database\Eloquent\Model;

/**
 * Restores one sandbox row from its active table counterpart.
 */
class SandboxRecordRestorer
{
    /**
     * Restore the sandbox row that matches the given active model.
     *
     * @throws SandboxException
     */
    public function restore(Model $model): void
    {
        $this->ensureRestorableModel($model);

        $keyColumns = $this->keyColumns($model);
        $keyValues = $this->keyValues($model, $keyColumns);

        if ($this->hasMissingKeyValues($keyValues, $keyColumns)) {
            return;
        }

        $columns = $this->syncColumnsFor($model, $keyColumns);
        $row = $model->getConnection()->table($model->getActiveTable())->where($keyValues)->first(
            $columns,
        );

        if ($row === null) {
            $model->getConnection()->table($model->getSandboxTable())->where($keyValues)->delete();

            return;
        }

        $this->writeSandboxRow($model, $keyValues, (array) $row);
    }

    /**
     * Ensure the model exposes the APIs required for a single-record restore.
     *
     * @throws SandboxException
     */
    private function ensureRestorableModel(Model $model): void
    {
        foreach (['getActiveTable', 'getSandboxTable', 'getSandboxPrimaryKey'] as $method) {
            throw_unless(
                method_exists($model, $method),
                SandboxException::class,
                sprintf(
                    'Model %s must use HasSandbox trait for single-record reset.',
                    $model::class,
                ),
                SandboxException::CODE_MODEL_NOT_REGISTERED,
            );
        }
    }

    /**
     * Get the key columns used to match active and sandbox rows.
     *
     * @return array<int, string>
     */
    private function keyColumns(Model $model): array
    {
        $keyName = $model->getSandboxPrimaryKey();

        return is_array($keyName) ? $keyName : [$keyName];
    }

    /**
     * Get key values from the model instance.
     *
     * @param array<int, string> $keyColumns
     *
     * @return array<string, mixed>
     */
    private function keyValues(Model $model, array $keyColumns): array
    {
        return array_intersect_key($model->getAttributes(), array_flip($keyColumns));
    }

    /**
     * Determine if the model has enough key values to restore a row.
     *
     * @param array<string, mixed> $keyValues
     * @param array<int, string>   $keyColumns
     */
    private function hasMissingKeyValues(array $keyValues, array $keyColumns): bool
    {
        return count($keyValues) !== count($keyColumns) || in_array(null, $keyValues, true);
    }

    /**
     * Get the columns used for a single-record sandbox restore.
     *
     * @param array<int, string> $keyColumns
     *
     * @throws SandboxException
     *
     * @return array<int, string>
     */
    private function syncColumnsFor(Model $model, array $keyColumns): array
    {
        throw_unless(
            method_exists($model, 'getSandboxWritableColumns'),
            SandboxException::class,
            sprintf('Model %s must expose sandbox writable columns.', $model::class),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );

        return array_values(array_unique([
            ...$keyColumns,
            ...$model->getSandboxWritableColumns(),
        ]));
    }

    /**
     * Insert or update the matching sandbox row.
     *
     * @param array<string, mixed> $keyValues
     * @param array<string, mixed> $attributes
     */
    private function writeSandboxRow(
        Model $model,
        array $keyValues,
        array $attributes,
    ): void {
        $query = $model->getConnection()->table($model->getSandboxTable())->where($keyValues);

        if (! $query->exists()) {
            $model->getConnection()->table($model->getSandboxTable())->insert($attributes);

            return;
        }

        $values = $attributes;
        foreach ($this->keyColumns($model) as $keyColumn) {
            unset($values[$keyColumn]);
        }

        if ($values !== []) {
            $model->getConnection()->table($model->getSandboxTable())->where($keyValues)->update(
                $values,
            );
        }
    }
}
