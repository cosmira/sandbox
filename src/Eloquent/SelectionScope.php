<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/** Nestable operation state, including models discovered during the callback. */
final class SelectionScope
{
    /**
     * Models selected through the registry during the current operation.
     *
     * @var list<class-string<Model>>
     */
    public array $switched = [];

    /**
     * Snapshots restored as nested table-selection operations finish.
     *
     * @var list<SelectionFrame>
     */
    private array $frames = [];

    /**
     * Connection required by the current edit, inherited by nested operations.
     */
    public ?ConnectionInterface $connection = null;

    /**
     * Record a model in the innermost active selection frame.
     *
     * @param class-string<Model> $model
     */
    public function remember(string $model): void
    {
        $frame = end($this->frames);
        if ($frame !== false) {
            $frame->remember($model);
        }
    }

    /**
     * Run a nested operation and restore selections and connection even on failure.
     */
    public function run(?ConnectionInterface $connection, callable $callback): mixed
    {
        $previousSwitched = $this->switched;
        $priorConnection = $this->connection;
        $this->connection = $connection ?? $priorConnection;
        $frame = new SelectionFrame();
        $this->frames[] = $frame;

        try {
            return $callback();
        } finally {
            array_pop($this->frames);
            $frame->restore();
            $this->switched = $previousSwitched;
            $this->connection = $priorConnection;
        }
    }
}
