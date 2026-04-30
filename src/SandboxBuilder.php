<?php

declare(strict_types=1);

namespace Packages\Sandbox;

use Illuminate\Database\Eloquent\Model;

/**
 * Fluent builder для удобного API управления sandbox-сессией.
 *
 * Пример использования:
 * ```php
 * Sandbox::for($userId)
 *     ->open()
 *     ->apply(Category::class)
 *     ->commit(note: 'Updated categories');
 * ```
 *
 * Все методы делегируют основному Sandbox классу, обеспечивая backward compatibility.
 */
class SandboxBuilder
{
    /** ID или UUID пользователя для этой сессии. */
    private int|string $userId;

    /** Основной Sandbox сервис. */
    private Sandbox $sandbox;

    /**
     * Инициализировать builder для пользователя.
     *
     * @param int|string $userId
     */
    public function __construct(int|string $userId)
    {
        $this->userId = $userId;
        $this->sandbox = app(Sandbox::class);
    }

    /**
     * Открыть sandbox-сессию для редактирования.
     *
     * @param bool        $force Пересилить если заблокирована другим пользователем
     * @param string|null $note  Опциональная заметка
     *
     * @return self
     */
    public function open(bool $force = false, ?string $note = null): self
    {
        $this->sandbox->open($this->userId, $force, $note);

        return $this;
    }

    /**
     * Закрыть sandbox-сессию с результатом "откат" (отменить все изменения).
     *
     * @param string|null $note
     *
     * @return void
     */
    public function rollback(?string $note = null): void
    {
        $this->sandbox->close($this->userId, 0, $note);
    }

    /**
     * Закрыть sandbox-сессию с результатом "коммит" (применить все изменения).
     *
     * @param string|null $note         Опциональная заметка
     * @param bool        $asyncUpdater Использовать асинхронное обновление
     *
     * @return void
     */
    public function commit(?string $note = null, bool $asyncUpdater = true): void
    {
        $this->sandbox->close($this->userId, 1, $note, $asyncUpdater);
    }

    /**
     * Закрыть sandbox-сессию с результатом "сохранить без коммита".
     *
     * @param string|null $note
     *
     * @return void
     */
    public function save(?string $note = null): void
    {
        $this->sandbox->close($this->userId, 2, $note);
    }

    /**
     * Применить данные в sandbox (скопировать из активной таблицы).
     *
     * @param class-string<Model>|Model $modelOrClass
     *
     * @return self
     */
    public function apply(string|Model $modelOrClass): self
    {
        $this->sandbox->resetSandboxData($modelOrClass);

        return $this;
    }

    /**
     * Сбросить данные в sandbox (перезагрузить из активной таблицы).
     *
     * @param class-string<Model>|Model $modelOrClass
     *
     * @return self
     */
    public function reset(string|Model $modelOrClass): self
    {
        $this->sandbox->resetSandboxData($modelOrClass);

        return $this;
    }

    /**
     * Получить текущий статус sandbox.
     *
     * @return Models\SandboxStatus|null
     */
    public function status(): ?Models\SandboxStatus
    {
        return $this->sandbox->status();
    }

    /**
     * Получить user ID этого builder.
     *
     * @return int|string
     */
    public function getUserId(): int|string
    {
        return $this->userId;
    }

    /**
     * Получить основной Sandbox объект для прямого доступа если требуется.
     *
     * @return Sandbox
     */
    public function getSandbox(): Sandbox
    {
        return $this->sandbox;
    }
}
