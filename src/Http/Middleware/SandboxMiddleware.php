<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Http\Middleware;

use Closure;
use Cosmira\Sandbox\Events\ResolvingSandboxModels;
use Cosmira\Sandbox\Models\SandboxStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Routes eligible requests through sandbox tables.
 */
class SandboxMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $status = SandboxStatus::first();

        if ($this->isWriteRequest($request)) {
            $this->ensureWriteIsAllowed($request, $status);
        }

        if ($this->shouldUseSandbox($request, $status)) {
            $this->resolveSandboxModels($request);
        }

        return $next($request);
    }

    /**
     * Reset request-local table switches after the response is sent.
     */
    public function terminate(Request $request, mixed $response): void
    {
        ResolvingSandboxModels::restoreActiveTables();
    }

    /**
     * Determine if the request should switch models to sandbox tables.
     */
    private function shouldUseSandbox(Request $request, ?SandboxStatus $status): bool
    {
        return $this->isWriteRequest($request) || $this->sandboxIsActive($status);
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
        $userId = $request->user()?->getAuthIdentifier();

        return $this->sandboxIsActive($status)
            && $status->user_id !== null
            && $userId !== null
            && (string) $status->user_id === (string) $userId;
    }

    /**
     * Resolve the models that should use sandbox tables for this request.
     */
    private function resolveSandboxModels(Request $request): void
    {
        Event::dispatch(new ResolvingSandboxModels($request));
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
