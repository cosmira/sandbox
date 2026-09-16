<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Contracts;

use Cosmira\Sandbox\Models\SandboxStatus;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Lifecycle mutations must lock and validate ownership on their connection.
 * Implementations must not commit an enclosing transaction owned by edit().
 */
interface SandboxBackend
{
    /**
     * Return the connection used for lifecycle locks and data changes.
     */
    public function connection(): ConnectionInterface;

    /**
     * The host must authorize force takeover before calling this method.
     */
    public function open(
        int|string $userId,
        bool $force = false,
        ?string $note = null,
    ): void;

    /**
     * Publish draft data and release the editing lock.
     */
    public function commit(int|string $userId, ?string $note = null): void;

    /**
     * Restore the draft from active data and release the editing lock.
     */
    public function rollback(int|string $userId, ?string $note = null): void;

    /**
     * Keep draft changes for later and release the editing lock.
     */
    public function save(int|string $userId, ?string $note = null): void;

    /**
     * Restore active data for one record or an entire model in the owned draft.
     */
    public function reset(int|string $userId, string|Model $modelOrClass): void;

    /**
     * Return the shared editing status, or null when no status row exists.
     */
    public function status(): ?SandboxStatus;
}
