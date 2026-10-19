<?php

declare(strict_types=1);

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Rules\Architecture\NoDependencyCycles;
use Cosmira\Soda\Rules\Documentation\MultilineConstantPhpDoc;
use Cosmira\Soda\Rules\Documentation\MultilineMethodPhpDoc;
use Cosmira\Soda\Rules\Documentation\MultilinePropertyPhpDoc;

return Soda::configure()
    ->withPaths([__DIR__.'/src'])
    ->with([
        ...array_map(
            static fn (Check $rule): Check => $rule instanceof NoDependencyCycles
                ? new NoDependencyCycles(['Cosmira\\Sandbox'])
                : $rule,
            RuleCatalog::standard(),
        ),
        new MultilineMethodPhpDoc(['public', 'protected', 'private']),
        new MultilinePropertyPhpDoc(['public', 'protected', 'private']),
        new MultilineConstantPhpDoc(['public', 'protected', 'private']),
    ]);
