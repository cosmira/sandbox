<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Http\Middleware;

use Closure;
use Cosmira\Sandbox\Events\SandboxResolvingModels;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Routes eligible requests through sandbox tables.
 */
class SandboxMiddleware
{
    /**
     * The registry that knows which models participate in sandbox mode.
     */
    private readonly SandboxModelRegistry $models;

    /**
     * The sandbox lifecycle manager.
     */
    private readonly Sandbox $sandbox;

    /**
     * Create a middleware instance.
     */
    public function __construct(
        ?SandboxModelRegistry $models = null,
        ?Sandbox $sandbox = null,
    ) {
        $this->models = $models ?? app(SandboxModelRegistry::class);
        $this->sandbox = $sandbox ?? app(Sandbox::class);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $status = SandboxStatus::first();

        if ($this->isWriteRequest($request)) {
            $status = $this->prepareSandboxForWrite($request, $status);
            $this->ensureWriteIsAllowed($request, $status);
        }

        if ($this->sandboxIsActive($status)) {
            $this->resolveSandboxModels($request);
        }

        return $next($request);
    }

    /**
     * Reset request-local table switches after the response is sent.
     */
    public function terminate(Request $request, mixed $response): void
    {
        SandboxResolvingModels::restoreActiveTables();
    }

    /**
     * Open or resume the sandbox for the current write request user.
     */
    private function prepareSandboxForWrite(Request $request, ?SandboxStatus $status): ?SandboxStatus
    {
        if ($status === null || $status->isLocked()) {
            return $status;
        }

        $userId = $request->user()?->getAuthIdentifier();

        abort_if(
            $userId === null,
            403,
            'An authenticated user is required to open the sandbox.',
        );

        if (! $status->isFree() && ! $status->isForUser($userId)) {
            return $status;
        }

        $this->sandbox->open($userId);

        return $this->sandbox->status();
    }

    /**
     * Reject write requests from users that do not own the active sandbox.
     */
    private function ensureWriteIsAllowed(Request $request, ?SandboxStatus $status): void
    {
        abort_if(
            ! $this->userOwnsActiveSandbox($request, $status),
            403,
            'Only the user that opened the sandbox may modify sandbox data.',
        );
    }

    /**
     * Determine if the current request may write into sandbox data.
     */
    private function userOwnsActiveSandbox(Request $request, ?SandboxStatus $status): bool
    {
        if (! $this->sandboxIsActive($status)) {
            return false;
        }

        $userId = $request->user()?->getAuthIdentifier();

        if ($userId === null) {
            return false;
        }

        return $status->isForUser($userId);
    }

    /**
     * Resolve the models that should use sandbox tables for this request.
     */
    private function resolveSandboxModels(Request $request): void
    {
        $this->models->useSandbox();

        Event::dispatch(new SandboxResolvingModels($request, $this->models));
    }

    /**
     * Determine if the request intends to change data.
     */
    private function isWriteRequest(Request $request): bool
    {
        return ! $request->isMethodSafe();
    }

    /**
     * Determine if the persisted sandbox session is currently active.
     */
    private function sandboxIsActive(?SandboxStatus $status): bool
    {
        return $status !== null && ! $status->isFree();
    }
}
