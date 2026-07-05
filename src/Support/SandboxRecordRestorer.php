<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        $this->ensureModelCanRestoreSingleRecord($model);

        $keyColumns = $this->keyColumns($model);
        $keyValues = $this->keyValues($model, $keyColumns);

        if ($this->missingKeyValues($keyValues, $keyColumns)) {
            return;
        }

        $columns = $this->syncColumnsFor($model, $keyColumns);
        $row = DB::table($model->getActiveTable())->where($keyValues)->first($columns);

        if ($row === null) {
            DB::table($model->getSandboxTable())->where($keyValues)->delete();
        } else {
            $this->writeSandboxRow($model, $keyValues, (array) $row, $keyColumns);
        }
    }

    /**
     * Ensure the model exposes the APIs required for a single-record restore.
     *
     * @throws SandboxException
     */
    private function ensureModelCanRestoreSingleRecord(Model $model): void
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
        $keyName = $model->getSandboxPrimaryKey();

        if (is_array($keyName)) {
            return array_intersect_key($model->getAttributes(), array_flip($keyColumns));
        }

        return [$keyName => $model->getKey()];
    }

    /**
     * Determine if the model has enough key values to restore a row.
     *
     * @param array<string, mixed> $keyValues
     * @param array<int, string>   $keyColumns
     */
    private function missingKeyValues(array $keyValues, array $keyColumns): bool
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
     * @param array<int, string>   $keyColumns
     */
    private function writeSandboxRow(
        Model $model,
        array $keyValues,
        array $attributes,
        array $keyColumns,
    ): void {
        $query = DB::table($model->getSandboxTable())->where($keyValues);

        if (! $query->exists()) {
            DB::table($model->getSandboxTable())->insert($attributes);

            return;
        }

        $values = $attributes;
        foreach ($keyColumns as $keyColumn) {
            unset($values[$keyColumn]);
        }

        if ($values !== []) {
            DB::table($model->getSandboxTable())->where($keyValues)->update($values);
        }
    }
}
