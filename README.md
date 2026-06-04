# Sandbox

[![Coding Guidelines](https://github.com/cosmira/sandbox/actions/workflows/code-style.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/code-style.yml)
[![Tests](https://github.com/cosmira/sandbox/actions/workflows/phpunit.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/phpunit.yml)
[![Code Coverage](https://github.com/cosmira/sandbox/actions/workflows/coverage.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/coverage.yml)

Laravel-пакет для редактирования конфигурации в песочнице: пользователь меняет sandbox-копию данных, а приложение затем откатывает, коммитит или сохраняет сессию без коммита.

У каждой sandbox-модели две таблицы:

- активная таблица, например `category`
- sandbox-таблица, по умолчанию активная таблица + `_sb`, например `category_sb`

В пакете есть две отдельные части:

- **объект песочницы** управляет сессией: открывает, закрывает, применяет результат, хранит статус и выбрасывает события
- **модели с `HasSandbox`** умеют явно работать с active/sandbox-таблицами и синхронизировать данные между ними

Сам объект песочницы не переключает модели и не копирует данные между таблицами напрямую. Это делает приложение в слушателях событий.

## Объект Песочницы

Основной способ работы — facade builder:

```php
use Packages\Sandbox\Facades\Sandbox;

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
- `commit(note: null, asyncUpdater: true)` — применить sandbox-данные в active через события
- `rollback(note: null)` — отменить изменения через события
- `save(note: null)` — оставить sandbox-данные без применения
- `reset($modelOrClass)` — явно обновить sandbox-данные модели из active
- `apply($modelOrClass)` — то же самое, alias для `reset()`
- `status()` — получить текущий статус

Объект песочницы отвечает только за lifecycle и события. Например, при `commit()` он диспатчит `SandboxApplying`, но сами модели синхронизируются в слушателе.

## Результаты Сессии

Закрытие песочницы имеет три результата:

| Метод | Что делает объект песочницы | События |
| --- | --- | --- |
| `rollback()` | Освобождает сессию после отката | `SandboxResetting`, затем `SandboxClosed` |
| `commit()` | Освобождает сессию после применения | `SandboxApplying`, затем `SandboxClosed` |
| `save()` | Сохраняет sandbox-состояние без применения | `SandboxClosed` |

Открытие диспатчит:

- `SandboxResetting`, когда active-данные нужно скопировать в sandbox
- `SandboxOpened`, когда статус уже переведён в `Locked`

Если песочница заблокирована другим пользователем, `open()` выбросит `SandboxException`. Для принудительного открытия используйте `force: true`.

## Слушатели Событий

Регистрируйте слушатели в service provider приложения. В них вы решаете, какие модели переключить, синхронизировать или вернуть обратно.

```php
use Illuminate\Support\Facades\Event;
use Packages\Sandbox\Events\SandboxApplying;
use Packages\Sandbox\Events\SandboxClosed;
use Packages\Sandbox\Events\SandboxOpened;
use Packages\Sandbox\Events\SandboxResetting;

$models = [
    Category::class,
    Product::class,
    Term::class,
];

Event::listen(SandboxOpened::class, function (SandboxOpened $event) use ($models): void {
    foreach ($models as $model) {
        $model::useSandboxTable();
    }
});

Event::listen(SandboxResetting::class, function () use ($models): void {
    foreach ($models as $model) {
        $model::syncIntoSandbox();
    }
});

Event::listen(SandboxApplying::class, function () use ($models): void {
    foreach ($models as $model) {
        $model::syncIntoActive();
    }
});

Event::listen(SandboxClosed::class, function (SandboxClosed $event) use ($models): void {
    foreach ($models as $model) {
        $model::useActiveTable();
    }

    if ($event->asyncUpdater) {
        // Запустить обновления через очередь.
    }
});
```

Данные событий:

- `SandboxOpened`: `userId`, `force`, `note`
- `SandboxClosed`: `userId`, `result`, `closedAt`, `note`, `asyncUpdater`

Порядок моделей задаёт приложение. Обычно справочники идут перед зависимыми таблицами.

## Возможности Модели

Добавьте `HasSandbox` к каждой модели, у которой есть активная и sandbox-таблица.

```php
use Illuminate\Database\Eloquent\Model;
use Packages\Sandbox\HasSandbox;

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
| `$sandboxPrimaryKey` | `null` | Первичный ключ; можно указать массив для составного ключа |
| `$sandboxTrackChangeColumn` | `'change_date'` | Колонка для проверки изменённых строк |

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

Для синхронизации всей таблицы используйте методы модели:

```php
Category::syncIntoSandbox(); // active -> sandbox
Category::syncIntoActive();  // sandbox -> active
```

Для обновления одной записи в sandbox из active передайте модель объекту песочницы:

```php
Sandbox::for($userId)->reset($category);
```

## Artisan

```bash
php artisan sandbox:open 1 --note="Editing config"
php artisan sandbox:open 2 --force

php artisan sandbox:close 1 --result=1
php artisan sandbox:close 1 --result=0
php artisan sandbox:close 1 --result=2
php artisan sandbox:close 1 --result=1 --async

php artisan sandbox:status
php artisan sandbox:status --details
```

Значения `--result`:

- `0`: откат
- `1`: коммит
- `2`: сохранить без коммита

## Тестирование

В тестах используйте `SandboxTestHelpers`:

```php
use Packages\Sandbox\Testing\SandboxTestHelpers;

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

Колонка `status` использует enum `Packages\Sandbox\Enums\SandboxStatus`:

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

Для мутационного тестирования нужен coverage driver: PCOV, Xdebug или phpdbg. Настройки Infection находятся в `infection.json`.

## Ограничения

- Пакет управляет одной глобальной sandbox-сессией на приложение.
- `useSandboxTable()` — статический переключатель модели. После работы возвращайте модель через `useActiveTable()`.
- Очереди не наследуют состояние переключения таблиц. Передавайте контекст явно и переключайте модели внутри job.
