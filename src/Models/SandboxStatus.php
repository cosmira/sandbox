<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Models;

use Cosmira\Sandbox\Database\Factories\SandboxStatusFactory;
use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The persisted status row for the sandbox lifecycle.
 */
class SandboxStatus extends Model
{
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The primary key for the singleton status row.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
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
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status'         => SandboxStatusEnum::class,
        'last_operation' => SandboxOperation::class,
        'change_date'    => 'datetime',
        'send_date'      => 'datetime',
        'change_id'      => 'integer',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('sandbox.table', 'sandbox_status');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): SandboxStatusFactory
    {
        return SandboxStatusFactory::new();
    }

    /**
     * Determine if the sandbox is free.
     */
    public function isFree(): bool
    {
        return $this->status === SandboxStatusEnum::Free;
    }

    /**
     * Determine if the sandbox is locked.
     */
    public function isLocked(): bool
    {
        return $this->status === SandboxStatusEnum::Locked;
    }

    /**
     * Determine if the sandbox is saved.
     */
    public function isSaved(): bool
    {
        return $this->status === SandboxStatusEnum::Saved;
    }

    /**
     * Determine if the sandbox is locked by the given user.
     */
    public function isOwnedBy(int|string $userId): bool
    {
        return $this->isLocked() && (string) $this->user_id === (string) $userId;
    }

    /**
     * Convert the status to the legacy status array shape.
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
