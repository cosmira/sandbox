<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Http\Middleware;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class SandboxResponseRejected extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('The HTTP request did not succeed.');
    }
}
