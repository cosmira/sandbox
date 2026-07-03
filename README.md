# Sandbox

[![Coding Guidelines](https://github.com/cosmira/sandbox/actions/workflows/code-style.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/code-style.yml)
[![Tests](https://github.com/cosmira/sandbox/actions/workflows/phpunit.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/phpunit.yml)
[![Code Coverage](https://github.com/cosmira/sandbox/actions/workflows/coverage.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/coverage.yml)

Laravel-пакет для редактирования конфигурации в песочнице. Один пользователь
берёт управление конфигурацией на себя, приложение переводит выбранные модели
на sandbox-таблицы через middleware и событие, а завершение всегда происходит
явно: применить изменения, откатить их или сохранить черновик.

У каждой sandbox-модели две таблицы:

- активная таблица, например `category`
- sandbox-таблица, по умолчанию активная таблица + `_sb`, например
  `category_sb`

В пакете есть две отдельные части:

- **объект песочницы** управляет сессией: открывает, закрывает, применяет
  результат, хранит статус и выбрасывает события
- **модели с `HasSandbox`** умеют явно работать с active/sandbox-таблицами и
  синхронизировать данные между ними

Открытие и закрытие песочницы управляют только lifecycle. Приложение решает,
какие модели участвуют в sandbox, а пакет помогает безопасно переключить их
через middleware и события.

## Сценарий Конфигурации

Пакет рассчитан на workflow, где конфигурацию может менять только один
пользователь за раз:

1. Пользователь нажимает "Редактировать" и вызывает `Sandbox::me()->open()`.
2. Sandbox получает владельца и блокирует конфигурацию для остальных.
3. Маршруты конфигурации проходят через middleware `sandbox`.
4. Middleware проверяет, что изменяющий запрос делает владелец sandbox.
5. Middleware диспатчит `ResolvingSandboxModels`.
6. Приложение слушает событие и выбирает модели через `$event->models(...)`.
7. Эти модели начинают читать и писать через sandbox-таблицы.
8. Остальные пользователи видят, кто сейчас редактирует конфигурацию.
9. Чужие `POST`, `PUT`, `PATCH` и `DELETE` получают `403`.
10. Владелец вручную завершает работу через `commit()`, `rollback()` или `save()`.

Важно: `open()` не копирует active-данные в sandbox. Открытие только берёт
блокировку. Синхронизация происходит в конце: `commit()` применяет sandbox в
active, `rollback()` возвращает sandbox к active, а `save()` оставляет черновик
и не снимает блокировку.

Middleware тоже не открывает, не коммитит и не откатывает sandbox. Оно только
проверяет доступ и на время запроса включает sandbox-таблицы для моделей,
которые приложение выбрало в `ResolvingSandboxModels`.

## Объект Песочницы

Основной способ работы — facade builder:

```php
use Cosmira\Sandbox\Facades\Sandbox;

Sandbox::for($userId)->open(note: 'Editing categories');

// Пользователь меняет данные в приложении.

Sandbox::for($userId)->commit(note: 'Apply category changes');
```

Для текущего авторизованного пользователя:

```php
Sandbox::me()->open();
Sandbox::me()->rollback();
```

Методы builder:

- `open(force: false, note: null)` — открыть сессию
- `commit(note: null, asyncUpdater: true)` — применить sandbox-данные в active
  через события
- `rollback(note: null)` — отменить изменения через события
- `save(note: null)` — оставить sandbox-данные без применения
- `reset($modelOrClass)` — явно обновить sandbox-данные модели из active
- `apply($modelOrClass)` — то же самое, alias для `reset()`
- `status()` — получить текущий статус

Объект песочницы отвечает только за lifecycle и события. Например, при
`commit()` он диспатчит `SandboxApplying`, но сами модели синхронизируются
в слушателе.

## Результаты Сессии

Закрытие песочницы имеет три результата:

| Метод | Что делает объект песочницы | События |
| --- | --- | --- |
| `rollback()` | Освобождает сессию после отката | `SandboxResetting`, `SandboxClosed` |
| `commit()` | Освобождает сессию после применения | `SandboxApplying`, `SandboxClosed` |
| `save()` | Сохраняет sandbox-состояние без применения | `SandboxClosed` |

Открытие диспатчит:

- `SandboxResetting`, для приложений, которым нужен eager refresh sandbox
- `SandboxOpened`, когда статус уже переведён в `Locked`

В типичном configuration workflow не нужно копировать active-данные в sandbox
при открытии. Пользователь продолжает работать с текущим sandbox-состоянием,
а синхронизация выполняется явно при завершении: `commit()` применяет sandbox
в active, `rollback()` откатывает sandbox из active.

Если песочница заблокирована другим пользователем, `open()` выбросит
`SandboxException`. Для принудительного открытия используйте `force: true`.

## Слушатели Событий

Регистрируйте слушатели в service provider приложения. В них вы решаете, какие
модели переключить, синхронизировать или вернуть обратно.

```php
use Illuminate\Support\Facades\Event;
use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Events\SandboxApplying;
use Cosmira\Sandbox\Events\SandboxClosed;

$models = [
    Category::class,
    Product::class,
    Term::class,
];

Event::listen(SandboxApplying::class, function () use ($models): void {
    foreach ($models as $model) {
        $model::syncIntoActive();
    }
});

Event::listen(SandboxClosed::class, function (SandboxClosed $event) use ($models): void {
    if ($event->result === SandboxOperation::Rollback) {
        foreach ($models as $model) {
            $model::syncIntoSandbox();
        }
    }

    if ($event->result !== SandboxOperation::Save) {
        foreach ($models as $model) {
            $model::useActiveTable();
        }
    }

    if ($event->asyncUpdater) {
        // Запустить обновления через очередь.
    }
});
```

Если приложение всё же хочет обновлять sandbox при каждом открытии, можно
слушать `SandboxResetting` и вызывать `syncIntoSandbox()`. Для
конфигурационного сценария обычно удобнее делать это только при rollback,
чтобы открытие sandbox не перетирало текущий черновик.

Данные событий:

- `SandboxOpened`: `userId`, `force`, `note`
- `SandboxClosed`: `userId`, `result`, `closedAt`, `note`, `asyncUpdater`

Порядок моделей задаёт приложение. Обычно справочники идут перед зависимыми
таблицами.

## Возможности Модели

Добавьте `HasSandbox` к каждой модели, у которой есть активная и
sandbox-таблица.

```php
use Illuminate\Database\Eloquent\Model;
use Cosmira\Sandbox\HasSandbox;

class Category extends Model
{
    use HasSandbox;

    protected $table = 'category';
    protected $primaryKey = 'category_id';
}
```

Настройки модели:

| Свойство | По умолчанию | Описание |
| --- | --- | --- |
| `$sandboxTablePostfix` | `'_sb'` | Суффикс sandbox-таблицы |
| `$sandboxPrimaryKey` | `null` | Первичный ключ; поддерживает составной ключ |
| `$sandboxTrackChangeColumn` | `'change_date'` | Колонка для изменённых строк |

Модель с `HasSandbox` умеет:

- явно читать из sandbox-таблицы
- явно читать из active-таблицы
- переключить обычные запросы модели на sandbox-таблицу
- вернуть обычные запросы модели на active-таблицу
- скопировать всю active-таблицу в sandbox
- скопировать всю sandbox-таблицу в active
- обновить одну запись в sandbox из active через объект песочницы

Для точечных запросов используйте scopes:

```php
Category::sandbox()->where('enabled', true)->get();
Category::active()->get();
```

Для блока работы переключайте модель явно:

```php
Category::useSandboxTable();

// чтение и запись Category идут в sandbox-таблицу

Category::useActiveTable();
```

Если sandbox уже включен, но конкретный блок должен явно работать с active
таблицей, используйте scoped helper:

```php
Category::withoutSandbox(function (): void {
    Category::query()->whereKey($id)->update([
        'value' => 'written-to-active',
    ]);
});
```

Для обратного случая есть `withSandbox()`. Оба helper восстанавливают прежний
режим модели после выполнения callback, включая исключения.

Для синхронизации всей таблицы используйте методы модели:

```php
Category::syncIntoSandbox(); // active -> sandbox
Category::syncIntoActive();  // sandbox -> active
```

## Middleware для Sandbox-Запросов

Пакет регистрирует middleware alias `sandbox`. Middleware вызывает
событие `ResolvingSandboxModels`, когда запрос изменяет данные (`POST`, `PUT`,
`PATCH`, `DELETE`) или когда sandbox-сессия уже активна в `sandbox_status`.

Подключите middleware к маршрутам конфигурации. Так приложение гарантирует,
что каждый запрос идёт через одну точку проверки владельца и переключения
моделей:

```php
Route::middleware('sandbox')->group(function (): void {
    Route::get('/categories', ListCategoryController::class);
    Route::post('/categories', StoreCategoryController::class);
    Route::put('/categories/{category}', UpdateCategoryController::class);
    Route::delete('/categories/{category}', DeleteCategoryController::class);
});
```

В приложении решите, какие модели входят в конфигурацию. Это намеренно
делается событием, а не конфигом пакета: приложение лучше знает порядок,
границы и зависимости своих моделей.

```php
use App\Models\Category;
use App\Models\Product;
use Cosmira\Sandbox\Events\ResolvingSandboxModels;
use Illuminate\Support\Facades\Event;

Event::listen(ResolvingSandboxModels::class, function (
    ResolvingSandboxModels $event,
): void {
    $event->models(Category::class, Product::class);
});
```

После этого обычный код контроллеров не должен знать про `_sb` таблицы.
Он продолжает работать через Eloquent:

```php
Category::query()->create($request->validated());
```

Если запрос идёт от владельца активной песочницы, запись попадёт в
sandbox-таблицу. Если песочница занята другим пользователем, изменяющий запрос
получит `403`.

Middleware сбрасывает только request-local переключатели моделей после отправки
ответа. Это защищает long-running процессы вроде Octane и RoadRunner от
протечки статического состояния между запросами. Сам sandbox-статус в базе не
закрывается: если sandbox активен, следующий запрос снова включит нужные модели
через `ResolvingSandboxModels`.

Модели, выбранные через `ResolvingSandboxModels`, пакет также вернет в
active-режим после `commit()` или `rollback()`. После `save()` sandbox остаётся
активным.

Изменяющие запросы требуют активную sandbox-сессию и проверяют её владельца.
Если песочница не открыта, её открыл другой пользователь, или изменить данные
пытается гость, middleware вернет `403`.

Для обновления одной записи в sandbox из active передайте модель объекту
песочницы:

```php
Sandbox::for($userId)->reset($category);
```

## Пользовательский Опыт

Хороший интерфейс поверх этого flow обычно показывает три состояния:

- свободно: можно нажать "Редактировать" и вызвать `open()`
- редактирует текущий пользователь: доступны "Применить", "Откатить" и
  "Сохранить черновик"
- редактирует другой пользователь: форма заблокирована, показан владелец

Изменения не применяются автоматически. Это важно для конфигурации: владелец
должен явно выбрать финальное действие.

| Действие | Результат |
| --- | --- |
| `commit()` | Применяет sandbox-данные в active и освобождает блокировку |
| `rollback()` | Возвращает sandbox к active и освобождает блокировку |
| `save()` | Оставляет черновик и сохраняет блокировку за владельцем |

`GET`/`HEAD` тоже могут читать sandbox-данные, если приложение подключило эти
модели в `ResolvingSandboxModels`. Так владелец видит свой черновик, а
остальные пользователи могут видеть состояние блокировки и работать в режиме
просмотра.

Для UI статуса достаточно отдавать данные из `Sandbox::me()->status()` или
`Sandbox::for($userId)->status()`:

```php
$status = Sandbox::me()->status()?->toStatusArray();
```

Если пользователь не является владельцем, приложение может показать имя
владельца из своей таблицы пользователей по `user_id` из статуса. Сам пакет
хранит только идентификатор владельца, чтобы не навязывать модель пользователя.

## Тестирование

В тестах используйте `SandboxTestHelpers`:

```php
use Cosmira\Sandbox\Testing\SandboxTestHelpers;

class ConfigControllerTest extends TestCase
{
    use SandboxTestHelpers;

    public function test_can_edit_config(): void
    {
        $this->openSandbox(userId: 1);
        $this->assertSandboxLocked(userId: 1);

        $this->useSandbox(Category::class);

        // Проверка кода приложения.

        $this->useActive(Category::class);
        $this->commitSandbox(userId: 1);
        $this->assertSandboxFree();
    }
}
```

Методы helper:

- `openSandbox(userId, force, note)`
- `commitSandbox(userId, note, async)`
- `rollbackSandbox(userId, note)`
- `saveSandbox(userId, note)`
- `assertSandboxFree()`
- `assertSandboxLocked(userId)`
- `assertSandboxSaved()`
- `getSandboxStatus()`
- `useSandbox(model)`
- `useActive(model)`
- `applySandbox(model)`

## Статус

`SandboxStatus` хранит одну глобальную sandbox-сессию приложения.

```php
$status = Sandbox::for($userId)->status();

$status?->isFree();
$status?->isLocked();
$status?->isSaved();
$status?->isOwnedBy($userId);
$status?->toStatusArray();
```

Колонка `status` использует enum `Cosmira\Sandbox\Enums\SandboxStatus`:

- `SandboxStatus::Free`
- `SandboxStatus::Locked`
- `SandboxStatus::Saved`

## Разработка

Запуск тестов:

```bash
composer test
```

Мутационное тестирование:

```bash
composer test:mutation
```

Для мутационного тестирования нужен coverage driver: PCOV, Xdebug или phpdbg.
Настройки Infection находятся в `infection.json`.

## Ограничения

- Пакет управляет одной глобальной sandbox-сессией на приложение.
- `useSandboxTable()` — статический переключатель модели. После работы
  возвращайте модель через `useActiveTable()`.
- Очереди не наследуют состояние переключения таблиц. Передавайте контекст
  явно и переключайте модели внутри job.
