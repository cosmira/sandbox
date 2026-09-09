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
    public function connection(): ConnectionInterface;

    /** The host must authorize force takeover before calling this method. */
    public function open(int|string $userId, bool $force = false, ?string $note = null): void;

    public function commit(int|string $userId, ?string $note = null): void;

    public function rollback(int|string $userId, ?string $note = null): void;

    public function save(int|string $userId, ?string $note = null): void;

    public function reset(int|string $userId, string|Model $modelOrClass): void;

    public function status(): ?SandboxStatus;
}
