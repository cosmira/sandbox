<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Eloquent;

use Illuminate\Database\Eloquent\Model;

/** Original selections restored together when a nested operation finishes. */
final class SelectionFrame
{
    /** @var array<class-string<Model>, bool> */
    private array $selections = [];

    /** @param class-string<Model> $model */
    public function remember(string $model): void
    {
        $this->selections[$model] ??= $model::isUsingSandbox();
    }

    public function restore(): void
    {
        foreach ($this->selections as $model => $draft) {
            $draft ? $model::useSandbox() : $model::useActive();
        }
    }
}
