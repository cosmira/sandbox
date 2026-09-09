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
     * @return Builder<SandboxStatus>
     */
    public function query(SandboxStatus $model): Builder
    {
        if ($model->getConnection()->transactionLevel() === 0) {
            throw new \LogicException('Sandbox status locking requires a transaction.');
        }

        if ($model->getConnection()->getDriverName() === 'sqlite') {
            // SQLite has no FOR UPDATE; reserve the write lock before reading a snapshot.
            $model->newQuery()->toBase()->update(['status' => new Expression('status')]);
        }

        return $model->newQuery()->lockForUpdate();
    }
}
