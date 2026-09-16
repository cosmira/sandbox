<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/** Nestable operation state, including models discovered during the callback. */
final class SelectionScope
{
    /** @var list<class-string<Model>> */
    public array $switched = [];

    /** @var list<SelectionFrame> */
    private array $frames = [];

    public ?ConnectionInterface $connection = null;

    /** @param class-string<Model> $model */
    public function remember(string $model): void
    {
        $frame = end($this->frames);
        if ($frame !== false) {
            $frame->remember($model);
        }
    }

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
