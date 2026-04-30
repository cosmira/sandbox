<?php

declare(strict_types=1);

namespace Packages\Sandbox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Packages\Sandbox\Database\Factories\SandboxStatusFactory;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;

class SandboxStatus extends Model
{
    use HasFactory;

    /** Модель не использует timestamps автоматически. */
    public $timestamps = false;

    /** Первичный ключ не автоинкрементируется. */
    public $incrementing = false;

    /** Первичный ключ не определен (используется значение по умолчанию). */
    protected $primaryKey;

    /**
     * Атрибуты, которые можно массово присваивать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'status',
        'last_operation',
        'note',
        'change_date',
        'user_id',
        'change_id',
        'send_date',
    ];

    /**
     * Приведение типов атрибутов.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status'         => SandboxStatusEnum::class,
        'last_operation' => 'integer',
        'change_date'    => 'datetime',
        'send_date'      => 'datetime',
        'change_id'      => 'integer',
        // user_id не приводим к int — может быть UUID (string)
    ];

    /**
     * Получить имя таблицы модели.
     *
     * @return string
     */
    public function getTable(): string
    {
        return config('sandbox.table', 'sandbox_status');
    }

    protected static function newFactory(): SandboxStatusFactory
    {
        return SandboxStatusFactory::new();
    }

    /**
     * Проверить, что песочница свободна (не используется).
     *
     * @return bool
     */
    public function isFree(): bool
    {
        return $this->status === SandboxStatusEnum::Free;
    }

    /**
     * Проверить, что песочница заблокирована пользователем.
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->status === SandboxStatusEnum::Locked;
    }

    /**
     * Проверить, что песочница сохранена (не коммичена).
     *
     * @return bool
     */
    public function isSaved(): bool
    {
        return $this->status === SandboxStatusEnum::Saved;
    }

    /**
     * Проверить, что sandbox заблокирован этим пользователем.
     */
    public function isOwnedBy(int|string $userId): bool
    {
        return $this->isLocked() && (string) $this->user_id === (string) $userId;
    }

    /**
     * Массив для API: status и user_id.
     *
     * @return array{status: int, user_id: int|string|null}
     */
    public function toStatusArray(): array
    {
        return [
            'status'  => $this->status->value,
            'user_id' => $this->user_id,
        ];
    }
}
