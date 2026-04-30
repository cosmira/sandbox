<?php

declare(strict_types=1);

namespace Packages\Sandbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Facades\DB;

/**
 * Трейт для Eloquent-моделей: параметры Sandbox и логика синхронизации (Laravel-style Has*).
 *
 * Модель задаёт постфикс таблицы (_sb), primary key. Синхронизация active ↔ sandbox
 * делается методами трейта (delete orphans, update, insert). Переключение на sandbox-таблицу
 * — через useSandboxTable() / useActiveTable() или scope sandbox().
 */
trait HasSandbox
{
    /**
     * Постфикс имени таблицы sandbox (добавляется к активной таблице).
     */
    protected static string $sandboxTablePostfix = '_sb';

    /**
     * Имя первичного ключа в sandbox-таблице (null = как у модели).
     * Для pivot-таблиц можно задать массив колонок: ['term_id', 'category_id'].
     *
     * @var string|array<int, string>|null
     */
    protected static string|array|null $sandboxPrimaryKey = null;

    /**
     * Колонка для проверки изменений при update (null = обновлять все совпадающие по key).
     */
    protected static ?string $sandboxTrackChangeColumn = 'change_date';

    /**
     * Колонка изменений для sync. Переопределите в модели (return null) для таблиц без change_date.
     */
    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return static::$sandboxTrackChangeColumn;
    }

    /**
     * Использовать ли в текущем контексте sandbox-таблицу для getTableForQuery().
     * Для переключения таблицы в запросах используйте scope sandbox() / active() либо
     * переопределите getTable() в модели, вызывая getTableForQuery().
     */
    protected static bool $useSandboxTable = false;

    /**
     * Таблица активной области (та же, что getTable() модели без sandbox).
     */
    public function getActiveTable(): string
    {
        return parent::getTable();
    }

    /**
     * Таблица для запросов с учётом флага useSandboxTable (для переопределения getTable() в модели).
     */
    public function getTableForQuery(): string
    {
        return static::$useSandboxTable ? $this->getSandboxTable() : $this->getActiveTable();
    }

    /**
     * Постфикс таблицы sandbox.
     */
    public function getSandboxTablePostfix(): string
    {
        return static::$sandboxTablePostfix;
    }

    /**
     * Имя (или имена) первичного ключа для sandbox (та же колонка/колонки, что у модели).
     *
     * @return string|array<int, string>
     */
    public function getSandboxPrimaryKey(): string|array
    {
        return static::$sandboxPrimaryKey ?? $this->getKeyName();
    }

    /**
     * Колонки первичного ключа как массив (для join/where в sync).
     *
     * @return array<int, string>
     */
    private function getSandboxPrimaryKeyColumns(): array
    {
        $key = $this->getSandboxPrimaryKey();

        return is_array($key) ? $key : [$key];
    }

    /**
     * Полное имя sandbox-таблицы (например category_sb).
     */
    public function getSandboxTable(): string
    {
        return $this->getActiveTable().$this->getSandboxTablePostfix();
    }

    /**
     * Переключить модель на использование sandbox-таблицы (для последующих запросов).
     */
    public static function useSandboxTable(): void
    {
        static::$useSandboxTable = true;
    }

    /**
     * Переключить модель на использование активной таблицы (по умолчанию).
     */
    public static function useActiveTable(): void
    {
        static::$useSandboxTable = false;
    }

    /**
     * Проверить, что сейчас используется sandbox-таблица.
     */
    public static function isUsingSandboxTable(): bool
    {
        return static::$useSandboxTable;
    }

    /**
     * Scope: запросы к sandbox-таблице (без изменения глобального флага).
     */
    protected function scopeSandbox(Builder $query): Builder
    {
        return $query->from($this->getSandboxTable());
    }

    /**
     * Scope: запросы к активной таблице (явно).
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->from($this->getActiveTable());
    }

    /**
     * Синхронизировать данные из активной таблицы в sandbox (для этой модели).
     * Удаление лишних в sb, обновление изменённых, вставка недостающих.
     * Поддерживается составной ключ (pivot-таблицы).
     */
    public static function syncIntoSandbox(): void
    {
        $instance = new static();
        $activeTable = $instance->getActiveTable();
        $sandboxTable = $instance->getSandboxTable();
        $keyColumns = $instance->getSandboxPrimaryKeyColumns();
        $changeColumn = static::getSandboxTrackChangeColumn();

        DB::transaction(function () use ($instance, $activeTable, $sandboxTable, $keyColumns, $changeColumn): void {
            static::deleteOrphansInTarget($sandboxTable, $activeTable, $keyColumns);
            static::syncUpdatesFromSourceToTarget($instance, $sandboxTable, $activeTable, $keyColumns, $changeColumn, 'sb', 't');
            static::insertMissingIntoTarget($sandboxTable, $activeTable, $keyColumns);
        });
    }

    /**
     * Синхронизировать данные из sandbox в активную таблицу (для этой модели).
     * Поддерживается составной ключ (pivot-таблицы).
     */
    public static function syncIntoActive(): void
    {
        $instance = new static();
        $activeTable = $instance->getActiveTable();
        $sandboxTable = $instance->getSandboxTable();
        $keyColumns = $instance->getSandboxPrimaryKeyColumns();
        $changeColumn = static::getSandboxTrackChangeColumn();

        DB::transaction(function () use ($instance, $activeTable, $sandboxTable, $keyColumns, $changeColumn): void {
            static::deleteOrphansInTarget($activeTable, $sandboxTable, $keyColumns);
            static::syncUpdatesFromSourceToTarget($instance, $activeTable, $sandboxTable, $keyColumns, $changeColumn, 't', 'sb');
            static::insertMissingIntoTarget($activeTable, $sandboxTable, $keyColumns);
        });
    }

    /**
     * Удалить из целевой таблицы строки, ключей которых нет в исходной.
     *
     * @param array<int, string> $keyColumns
     */
    private static function deleteOrphansInTarget(string $targetTable, string $sourceTable, array $keyColumns): void
    {
        DB::table($targetTable)
            ->whereNotExists(function ($query) use ($sourceTable, $targetTable, $keyColumns): void {
                $query->select(DB::raw(1))->from($sourceTable);
                foreach ($keyColumns as $col) {
                    $query->whereColumn($sourceTable.'.'.$col, $targetTable.'.'.$col);
                }
            })
            ->delete();
    }

    /**
     * Обновить в целевой таблице строки, совпадающие по ключу с исходной (или удалить и вставить заново при pivot).
     *
     * @param array<int, string> $keyColumns
     */
    private static function syncUpdatesFromSourceToTarget(
        object $instance,
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        ?string $changeColumn,
        string $targetAlias,
        string $sourceAlias,
    ): void {
        $columns = $instance->getSandboxSyncColumns();
        if ($columns === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        if ($changeColumn === null) {
            static::deleteTargetRowsExistingInSource($targetTable, $sourceTable, $keyColumns, $grammar);
        } else {
            static::updateTargetFromSource($targetTable, $sourceTable, $keyColumns, $columns, $changeColumn, $grammar, $targetAlias, $sourceAlias);
        }
    }

    /**
     * @param array<int, string> $keyColumns
     */
    private static function deleteTargetRowsExistingInSource(string $targetTable, string $sourceTable, array $keyColumns, Grammar $grammar): void
    {
        $keyCondition = implode(' AND ', array_map(
            fn (string $col): string => $grammar->wrap($sourceTable.'.'.$col).' = '.$grammar->wrap($targetTable.'.'.$col),
            $keyColumns,
        ));
        DB::statement('
            DELETE FROM '.$grammar->wrapTable($targetTable).'
            WHERE EXISTS (
                SELECT 1 FROM '.$grammar->wrapTable($sourceTable).' WHERE '.$keyCondition.'
            )
        ');
    }

    /**
     * @param array<int, string> $keyColumns
     * @param array<int, string> $columns
     */
    private static function updateTargetFromSource(
        string $targetTable,
        string $sourceTable,
        array $keyColumns,
        array $columns,
        string $changeColumn,
        Grammar $grammar,
        string $targetAlias,
        string $sourceAlias,
    ): void {
        $updateSet = [];
        foreach (array_diff($columns, $keyColumns) as $col) {
            $updateSet[$targetAlias.'.'.$col] = DB::raw($grammar->wrap($sourceAlias.'.'.$col));
        }

        $query = DB::table($targetTable.' as '.$targetAlias)
            ->join($sourceTable.' as '.$sourceAlias, function ($join) use ($keyColumns, $targetAlias, $sourceAlias): void {
                foreach ($keyColumns as $col) {
                    $join->on($targetAlias.'.'.$col, '=', $sourceAlias.'.'.$col);
                }
            });
        if (in_array($changeColumn, $columns, true)) {
            $query->whereColumn($targetAlias.'.'.$changeColumn, '!=', $sourceAlias.'.'.$changeColumn);
        }

        $query->update($updateSet);
    }

    /**
     * Вставить в целевую таблицу строки из исходной, которых ещё нет в целевой по ключу.
     *
     * @param array<int, string> $keyColumns
     */
    private static function insertMissingIntoTarget(string $targetTable, string $sourceTable, array $keyColumns): void
    {
        $notExistsConditions = implode(' AND ', array_map(
            fn (string $col): string => sprintf('%s.%s = %s.%s', $targetTable, $col, $sourceTable, $col),
            $keyColumns,
        ));
        DB::statement("
            INSERT INTO {$targetTable}
            SELECT * FROM {$sourceTable}
            WHERE NOT EXISTS (
                SELECT 1 FROM {$targetTable} WHERE {$notExistsConditions}
            )
        ");
    }

    /**
     * Список колонок для синхронизации (по умолчанию — из первой строки таблицы).
     * Переопределите в модели для явного списка или если таблица пуста.
     *
     * @return array<int, string>
     */
    protected function getSandboxSyncColumns(): array
    {
        $row = DB::table($this->getActiveTable())->first();
        if ($row === null) {
            return [];
        }

        return array_keys((array) $row);
    }

    /**
     * Проверить, что модель участвует в Sandbox (использует трейт с параметрами).
     */
    public static function supportsSandboxSync(): bool
    {
        return true;
    }
}
