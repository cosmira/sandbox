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

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey;

    /**
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
     * @var array<string, string>
     */
    protected $casts = [
        'status'         => SandboxStatusEnum::class,
        'last_operation' => 'integer',
        'change_date'    => 'datetime',
        'send_date'      => 'datetime',
        'change_id'      => 'integer',
    ];

    public function getTable(): string
    {
        return config('sandbox.table', 'sandbox_status');
    }

    protected static function newFactory(): SandboxStatusFactory
    {
        return SandboxStatusFactory::new();
    }

    public function isFree(): bool
    {
        return $this->status === SandboxStatusEnum::Free;
    }

    public function isLocked(): bool
    {
        return $this->status === SandboxStatusEnum::Locked;
    }

    public function isSaved(): bool
    {
        return $this->status === SandboxStatusEnum::Saved;
    }

    public function isOwnedBy(int|string $userId): bool
    {
        return $this->isLocked() && (string) $this->user_id === (string) $userId;
    }

    /**
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
