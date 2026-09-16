<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Http\Middleware;

use Closure;
use Cosmira\Sandbox\Events\SandboxResolvingModels;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Sandbox;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SandboxMiddleware
{
    /**
     * Registry responsible for restoring model selections around each request.
     */
    private readonly SandboxModelRegistry $models;

    /**
     * Lifecycle service used to authorize ownership and transact draft edits.
     */
    private readonly Sandbox $sandbox;

    /**
     * Use supplied services or resolve the application defaults.
     */
    public function __construct(?SandboxModelRegistry $models = null, ?Sandbox $sandbox = null)
    {
        $this->models = $models ?? app(SandboxModelRegistry::class);
        $this->sandbox = $sandbox ?? app(Sandbox::class);
    }

    /**
     * Scope reads and transact edits, rolling back rejected HTTP responses.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user()?->getAuthIdentifier();

        if ($request->isMethodSafe()) {
            return $this->sandbox->read(
                $user, fn (bool $draft) => $this->resolve($request, $next, $draft),
            );
        }

        abort_if($user === null, 403, 'An authenticated user is required to open the sandbox.');

        try {
            return $this->sandbox->edit($user, function () use ($request, $next): mixed {
                $response = $this->resolve($request, $next, true);

                if ($response instanceof Response && $response->getStatusCode() >= 400) {
                    throw new SandboxResponseRejected($response);
                }

                return $response;
            });
        } catch (SandboxResponseRejected $exception) {
            return $exception->response;
        } catch (SandboxException $exception) {
            if ($exception->getCode() !== SandboxException::CODE_SANDBOX_LOCKED) {
                throw $exception;
            }

            throw new HttpException(403, $exception->getMessage(), $exception);
        } catch (ModelNotFoundException $exception) {
            if ($exception->getModel() !== SandboxStatus::class) {
                throw $exception;
            }

            abort(403, 'The sandbox status row is missing.');
        }
    }

    /**
     * Select request tables and discover extra draft models before dispatch.
     */
    private function resolve(Request $request, Closure $next, bool $draft): mixed
    {
        return $this->models->usingTables($draft, function () use ($request, $next, $draft): mixed {
            if ($draft) {
                $this->models->useSandbox();
                Event::dispatch(new SandboxResolvingModels($request, $this->models));
            }

            return $next($request);
        });
    }

    /**
     * Retain the middleware hook; request table state is restored inside handle().
     */
    public function terminate(Request $request, mixed $response): void {}
}
