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

        if (! $request->isMethodSafe()) {
            $this->rejectIfOwnedByAnotherUser($request, $status);
        }

        if ($this->shouldUseSandbox($request, $status)) {
            Event::dispatch(new ResolvingSandboxModels($request));
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
        return ! $request->isMethodSafe() || $this->sandboxIsActive($status);
    }

    /**
     * Reject write requests from users that do not own the active sandbox.
     */
    private function rejectIfOwnedByAnotherUser(Request $request, ?SandboxStatus $status): void
    {
        $userId = $request->user()?->getAuthIdentifier();

        abort_if(
            ! $this->sandboxIsActive($status)
                || $status->user_id === null
                || $userId === null
                || (string) $status->user_id !== (string) $userId,
            403,
            'Only the user that opened the sandbox may modify sandbox data.',
        );
    }

    /**
     * Determine if the persisted sandbox session is currently active.
     */
    private function sandboxIsActive(?SandboxStatus $status): bool
    {
        return $status !== null && ! $status->isFree();
    }
}
