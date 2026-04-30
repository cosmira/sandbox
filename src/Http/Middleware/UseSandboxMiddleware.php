<?php

declare(strict_types=1);

namespace Packages\Sandbox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Packages\Sandbox\Facades\Sandbox;

/**
 * Middleware для автоматического переключения моделей на sandbox-таблицы.
 *
 * Если песочница открыта и принадлежит текущему пользователю, все модели
 * с трейтом HasSandbox будут использовать sandbox-таблицы.
 *
 * Пример использования:
 * ```php
 * // bootstrap/app.php
 * $app->make(Illuminate\Routing\Router::class)->middleware([
 *     'sandbox' => \Packages\Sandbox\Http\Middleware\UseSandboxMiddleware::class,
 * ]);
 * ```
 *
 * Или создайте middleware group:
 * ```php
 * ->withMiddleware(function (Middleware $middleware) {
 *     $middleware->group('sandbox', [
 *         UseSandboxMiddleware::class,
 *     ]);
 * })
 * ```
 *
 * Затем используйте в routes:
 * ```php
 * Route::group(['middleware' => 'sandbox'], function () {
 *     Route::post('/config/save', ConfigController::class . '@save');
 * });
 * ```
 */
class UseSandboxMiddleware
{
    /**
     * Список моделей которые поддерживают sandbox.
     * Переопределите эту переменную в своем middleware или передайте параметром.
     *
     * @var array<int, class-string>
     */
    protected array $models = [];

    /**
     * Конструктор может принять список моделей как параметр.
     *
     * @param array<int, class-string> $models
     */
    public function __construct(array $models = [])
    {
        $this->models = $models;
    }

    /**
     * Handle the incoming request.
     *
     * @param Closure(Request): (Response|null) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $status = Sandbox::status();
        $userId = $request->user()?->id;

        // Только переключаемся на sandbox если:
        // 1. Sandbox открыта (status не null)
        // 2. Пользователь авторизован (userId не null)
        // 3. Песочница принадлежит текущему пользователю
        if ($status && $userId && $status->isLocked() && $status->isOwnedBy($userId)) {
            // Переключить все модели на sandbox-таблицы
            foreach ($this->models as $model) {
                if (method_exists($model, 'useSandboxTable')) {
                    $model::useSandboxTable();
                }
            }
        }

        return $next($request);
    }
}
