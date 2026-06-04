<?php

declare(strict_types=1);

namespace Packages\Sandbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\Dumpable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Tappable;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Events\SandboxApplying;
use Packages\Sandbox\Events\SandboxClosed;
use Packages\Sandbox\Events\SandboxOpened;
use Packages\Sandbox\Events\SandboxResetting;
use Packages\Sandbox\Exceptions\SandboxException;
use Packages\Sandbox\Models\SandboxStatus;

/**
 * Синглтон для открытия/закрытия сессии и синхронизации данных.
 *
 * Получить: app(Sandbox::class) или фасад \Packages\Sandbox\Facades\Sandbox.
 * События синхронизации данных — в слушателях вызываете свой синхронизатор (модели с HasSandbox).
 *
 * @see README.md
 */
class Sandbox
{
    use Dumpable;
    use Macroable;
    use Tappable;

    /**
     * Открыть sandbox (начать редактирование).
     *
     * @param int|string $user ID или UUID пользователя
     *
     * @throws SandboxException Если sandbox заблокирован другим пользователем
     */
    public function open(int|string|Model $user, bool $force = false, ?string $note = null): void
    {
        Log::debug('Opening sandbox', ['user_id' => $user]);

        $userId = $user instanceof Model ? $user->getKey() : $user;

        DB::transaction(function () use ($userId, $force, $note): void {
            $status = SandboxStatus::first();

            throw_unless($status, \RuntimeException::class, 'Sandbox status not found');

            if (! $force && $status->isLocked() && (string) $status->user_id !== (string) $userId) {
                throw new SandboxException(
                    'Sandbox is locked by other user '.$status->user_id,
                    SandboxException::CODE_SANDBOX_LOCKED,
                );
            }

            if ($status->isFree() || ($force && $status->isLocked() && (string) $status->user_id !== (string) $userId)) {
                Event::dispatch(new SandboxResetting());
            }

            $status->update([
                'status'      => SandboxStatusEnum::Locked,
                'user_id'     => $userId,
                'note'        => $note,
                'change_date' => now(),
            ]);

            Event::dispatch(new SandboxOpened($userId, $force, $note));

            Log::info('Sandbox opened', ['user_id' => $userId]);
        });
    }

    /**
     * Закрыть sandbox (завершить редактирование).
     *
     * @param int|string $userId       ID или UUID пользователя
     * @param int        $result       0 — откат, 1 — коммит, 2 — сохранить без коммита
     * @param bool       $asyncUpdater Передаётся в SandboxClosed для выбора способа обновления
     *
     * @throws SandboxException
     */
    public function close(int|string $userId, int $result, ?string $note = null, bool $asyncUpdater = true): void
    {
        Log::debug('Closing sandbox', ['user_id' => $userId, 'result' => $result]);

        DB::transaction(function () use ($userId, $result, $note, $asyncUpdater): void {
            $status = SandboxStatus::firstOrFail();

            throw_if($status->isFree(), SandboxException::class, 'Cannot close: sandbox is already free. Use open() first.', SandboxException::CODE_SANDBOX_FREE);

            if ($status->isLocked() && (string) $status->user_id !== (string) $userId && $result !== 0) {
                throw new SandboxException(
                    'Sandbox is locked by other user '.$status->user_id,
                    SandboxException::CODE_SANDBOX_LOCKED,
                );
            }

            match ($result) {
                0       => $this->handleRollback($status, $userId, $note),
                1       => $this->handleCommit($status, $userId, $note, $asyncUpdater),
                2       => $this->handleSave($status, $userId, $note),
                default => throw new SandboxException(
                    'Unknown result: '.$result,
                    SandboxException::CODE_SANDBOX_EDIT_RESULT,
                ),
            };

            Log::info('Sandbox closed', ['user_id' => $userId, 'result' => $result]);
        });
    }

    /**
     * @deprecated Используйте open()
     */
    public function beginEdit(int|string $userId, bool $force = false, ?string $note = null): void
    {
        $this->open($userId, $force, $note);
    }

    /**
     * @deprecated Используйте close()
     */
    public function endEdit(int|string $userId, int $result, ?string $note = null, bool $asyncUpdater = true): void
    {
        $this->close($userId, $result, $note, $asyncUpdater);
    }

    private function handleRollback(SandboxStatus $status, int|string $userId, ?string $note): void
    {
        $closedAt = now();

        Event::dispatch(new SandboxResetting());

        $status->update([
            'status'         => SandboxStatusEnum::Free,
            'user_id'        => $userId,
            'last_operation' => 0,
            'note'           => $note,
            'change_date'    => $closedAt,
        ]);

        Event::dispatch(new SandboxClosed($userId, 0, $closedAt, $note, false));
    }

    private function handleCommit(SandboxStatus $status, int|string $userId, ?string $note, bool $asyncUpdater): void
    {
        $closedAt = now();

        Event::dispatch(new SandboxApplying());

        $status->update([
            'status'         => SandboxStatusEnum::Free,
            'user_id'        => $userId,
            'last_operation' => 1,
            'note'           => $note,
            'send_date'      => $closedAt,
            'change_date'    => $closedAt,
        ]);

        Event::dispatch(new SandboxClosed($userId, 1, $closedAt, $note, $asyncUpdater));
    }

    private function handleSave(SandboxStatus $status, int|string $userId, ?string $note): void
    {
        $closedAt = now();

        $status->update([
            'status'         => SandboxStatusEnum::Saved,
            'user_id'        => $userId,
            'last_operation' => 2,
            'note'           => $note,
            'change_date'    => $closedAt,
        ]);

        Event::dispatch(new SandboxClosed($userId, 2, $closedAt, $note, false));
    }

    /**
     * Текущий статус sandbox.
     *
     * Использование: Sandbox::status()?->status, ->user_id, ->isLocked(), ->isOwnedBy($userId)
     */
    public function status(): ?SandboxStatus
    {
        return SandboxStatus::first();
    }

    /**
     * @deprecated Используйте status()
     */
    public function getStatus(): array
    {
        $model = $this->status();

        return [
            'status'  => $model->status?->value ?? 0,
            'user_id' => $model->user_id ?? null,
        ];
    }

    /**
     * @deprecated Используйте status()?->isOwnedBy($userId)
     */
    public function isSandboxUser(int|string $userId): bool
    {
        return $this->status()?->isOwnedBy($userId) ?? false;
    }

    /**
     * Сбросить Sandbox данные для модели (только для моделей с syncIntoSandbox).
     *
     * Класс — сброс массово (syncIntoSandbox). Экземпляр — сброс одной записи (копирование строки).
     *
     * @param class-string<Model>|Model $modelOrClass
     */
    public function resetSandboxData(string|Model $modelOrClass): void
    {
        $this->ensureModelCanSync($modelOrClass);

        $instance = $modelOrClass instanceof Model ? $modelOrClass : null;
        $modelClass = $instance instanceof Model ? $instance::class : $modelOrClass;

        if ($instance instanceof Model) {
            $this->resetSingleRecord($instance);
        } else {
            $this->resetBulk($modelClass);
        }
    }

    /**
     * @param class-string<Model>|Model $modelOrClass
     */
    private function ensureModelCanSync(string|Model $modelOrClass): void
    {
        $modelClass = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;

        throw_unless(
            method_exists($modelClass, 'syncIntoSandbox'),
            SandboxException::class,
            sprintf('Model %s has no syncIntoSandbox(). Use HasSandbox trait.', $modelClass), SandboxException::CODE_MODEL_NOT_REGISTERED
        );
    }

    private function resetSingleRecord(Model $model): void
    {
        throw_unless(
            method_exists($model, 'getSandboxTable'),
            SandboxException::class,
            'Model '.$model::class.' must use HasSandbox trait for single-record reset.',
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );

        $table = $model->getActiveTable();
        $sandboxTable = $model->getSandboxTable();
        $keyName = $model->getSandboxPrimaryKey();
        $keyColumns = is_array($keyName) ? $keyName : [$keyName];
        $keyValues = is_array($keyName)
            ? array_intersect_key($model->getAttributes(), array_flip($keyColumns))
            : [$keyName => $model->getKey()];

        if (count($keyValues) !== count($keyColumns) || in_array(null, $keyValues, true)) {
            return;
        }

        $row = DB::table($table)->where($keyValues)->first();

        if ($row === null) {
            DB::table($sandboxTable)->where($keyValues)->delete();

            return;
        }

        $exists = DB::table($sandboxTable)->where($keyValues)->exists();
        if ($exists) {
            DB::table($sandboxTable)->where($keyValues)->update((array) $row);
        } else {
            DB::table($sandboxTable)->insert((array) $row);
        }
    }

    private function resetBulk(string $modelClass): void
    {
        $modelClass::syncIntoSandbox();
    }
}
