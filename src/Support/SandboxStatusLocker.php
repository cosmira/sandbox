<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Support;

use Cosmira\Sandbox\Models\SandboxStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

final class SandboxStatusLocker
{
    /**
     * The caller must already own the enclosing transaction.
     *
     * @template TStatus of SandboxStatus
     *
     * @param TStatus $model
     *
     * @return Builder<TStatus>
     */
    public function query(SandboxStatus $model): Builder
    {
        $connection = $model->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new \LogicException('Sandbox status locking requires a transaction.');
        }

        if ($connection->getDriverName() === 'sqlite') {
            // SQLite has no FOR UPDATE; reserve the write lock before reading a snapshot.
            $model->newQuery()->toBase()->update(['status' => new Expression('status')]);
        }

        $query = $model->newQuery();
        $query->lockForUpdate();

        return $query;
    }
}
