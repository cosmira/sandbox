<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** Original selections restored together when a nested operation finishes. */
final class SelectionFrame
{
    /**
     * Original selection for each model first encountered in this operation.
     *
     * @var array<class-string<Model>, bool>
     */
    private array $selections = [];

    /**
     * Capture the original selection once, before the model is switched.
     *
     * @param class-string<Model> $model
     */
    public function remember(string $model): void
    {
        $this->selections[$model] ??= $model::isUsingSandbox();
    }

    /**
     * Restore every model to the selection captured when this operation began.
     */
    public function restore(): void
    {
        foreach ($this->selections as $model => $draft) {
            $draft ? $model::useSandbox() : $model::useActive();
        }
    }
}
