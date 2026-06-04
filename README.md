# Sandbox

Пакет для редактирования конфигурации в «песочнице»: изменения делаются в отдельной копии данных и постепенно перетекают в прод — через откат или коммит.

## Для чего нужна песочница

У каждой сущности — **две таблицы**:

- **Активная таблица** (прод) — например `category`. Её читают все пользователи.
- **Sandbox-таблица** — например `category_sb`. В ней пользователь правит данные, пока открыта сессия песочницы.

Поток данных:

1. **Открытие сессии** — данные из активных таблиц копируются в sandbox-таблицы. Дальше запросы модели идут в sandbox.
2. **Редактирование** — все изменения пишутся только в sandbox.
3. **Закрытие сессии**:
   - **Откат** — sandbox снова заполняется из активных таблиц (как при открытии), изменения в sandbox отбрасываются.
   - **Коммит** — данные из sandbox копируются в активные таблицы и становятся продом.
   - **Сохранить без коммита** — песочница просто освобождается, данные остаются в sandbox до следующего открытия.

Пакет не копирует данные сам: он диспатчит события, а вы в слушателях вызываете синхронизацию по своим моделям (см. ниже).

---

## Начало работы

### Добавьте трейт к модели

Для каждой сущности, у которой есть активная и sandbox-таблица, подключите трейт `HasSandbox`:

```php
use Illuminate\Database\Eloquent\Model;
use Packages\Sandbox\HasSandbox;

class Category extends Model
{
    use HasSandbox;
}
```

По умолчанию sandbox-таблица = активная таблица + `_sb` (например `category_sb`). 
Ключ и колонка изменений можно переопределить (см. раздел «Трейт HasSandbox» ниже).

### Откройте песочницу

Когда пользователь начинает редактирование конфигурации, откройте сессию:

```php
use Packages\Sandbox\Sandbox;

$sandbox = app(Sandbox::class);

// Открыть песочницу для пользователя (при первом открытии или после отката данные active → sandbox подтянутся по событию)
$sandbox->open($userId, force: false, note: null);
$sandbox->close($userId, result: EnumValue, note: null, asyncUpdater: true);

// Важно песочница не должна ничего делать или работать с БД сама — она только диспатчит события, а мы в слушателях выполняем синхронизацию данных между таблицами.

- **Откат (0)** — диспатчится событие MergeIntoSandboxRequested
- **Коммит (1)** — 
диспатчится MergeIntoActiveRequested скопировать данные из sandbox в активные таблицы. 
После обновления статуса диспатчится SandboxCommitted важно, что бы можно запускать постобработку (обновление кэшей, очереди и т.п.).
- **Сохранить без коммита (2)** — песочница освобождается без копирования; событий мержа нет.

$sandbox->status()->isFree();
$sandbox->status()->isLocked();
$sandbox->status()->isOwnedBy($user);

// Сбросить/применить данные в песочнице
$sandbox->reset(Category::class);        // вся таблица
$sandbox->apply(Category::class);        // вся таблица

$sandbox->reset($category);              // одна запись
$sandbox->apply($category);              // одна запись

//Важно 

- **Откат (0)** — диспатчится событие **`MergeIntoSandboxRequested`**: в слушателе нужно скопировать данные из активных таблиц в sandbox (см. ниже). Затем статус песочницы станет «свободен».
- **Коммит (1)** — диспатчится **`MergeIntoActiveRequested`**: скопировать данные из sandbox в активные таблицы. После обновления статуса диспатчится **`SandboxCommitted`** (userId, sendDate, asyncUpdater) — можно запускать постобработку (обновление кэшей, очереди и т.п.).
- **Сохранить без коммита (2)** — песочница освобождается без копирования; событий мержа нет.

```

После этого ваши модели должны читать и писать в sandbox-таблицы.
Переключение делается в приложении: перед блоками кода, которые работают с конфигом, вызывайте `Model::useSandboxTable()` и по завершении при необходимости `Model::useActiveTable()`. Либо используйте scope: `Category::sandbox()->get()` / `Category::active()->get()` вместо переключения глобального флага.

### Освободите песочницу (закройте сессию)

Когда пользователь закончил правки:

```php
// result: 0 — откат, 1 — коммит, 2 — сохранить без коммита
$sandbox->close($userId, result: 1, note: null, asyncUpdater: true);
```



- **Откат (0)** — диспатчится событие **`MergeIntoSandboxRequested`**: в слушателе нужно скопировать данные из активных таблиц в sandbox (см. ниже). Затем статус песочницы станет «свободен».
- **Коммит (1)** — диспатчится **`MergeIntoActiveRequested`**: скопировать данные из sandbox в активные таблицы. После обновления статуса диспатчится **`SandboxCommitted`** (userId, sendDate, asyncUpdater) — можно запускать постобработку (обновление кэшей, очереди и т.п.).
- **Сохранить без коммита (2)** — песочница освобождается без копирования; событий мержа нет.

При попытке закрыть уже свободную песочницу выбрасывается `SandboxException` (код 20606).

---

## События и подписка в Service Provider

Пакет не выполняет слияние данных самостоятельно. Он диспатчит события; ваше приложение подписывается на них и выполняет копирование данных между таблицами и пост-коммит логику.

### MergeIntoSandboxRequested

Событие диспатчится, когда данные из активной области нужно скопировать в sandbox-таблицы. Это происходит:

- при **открытии** песочницы (если sandbox был свободен или при force-open) — чтобы sandbox начинался с текущего состояния активной области;
- при **откате** пользователя — чтобы sandbox снова совпал с активной областью.

**Обязанность слушателя:** скопировать данные из активных таблиц в sandbox-таблицы (например, вызвать `syncIntoSandbox()` для моделей с `HasSandbox`).

### MergeIntoActiveRequested

Событие диспатчится, когда данные из sandbox нужно скопировать в активные таблицы. Это происходит при **коммите** пользователя (до обновления статуса).

**Обязанность слушателя:** скопировать данные из sandbox-таблиц в активные (например, вызвать `syncIntoActive()` для моделей с `HasSandbox`).

### SandboxCommitted

Событие диспатчится после успешного коммита. Статус уже обновлён (например, на «свободна»); вы можете запустить пост-коммит задачи (обновление материализованных представлений, перегенерация конфигов и т.п.).

**Данные события:**
- `userId` — пользователь, выполнивший коммит;
- `sendDate` — время коммита;
- `asyncUpdater` — булево значение, переданное в `close()`; используйте его, чтобы решить, выполнять обновления синхронно или через очередь.

### Регистрация слушателей

Зарегистрируйте слушатели в методе `boot()` сервис-провайдера (например, `AppServiceProvider` или отдельном провайдере):

```php
use Illuminate\Support\Facades\Event;
use Packages\Sandbox\Events\MergeIntoActiveRequested;
use Packages\Sandbox\Events\MergeIntoSandboxRequested;
use Packages\Sandbox\Events\SandboxCommitted;

$models = [Category::class, Product::class, Term::class]; // модели с HasSandbox

Event::listen(MergeIntoSandboxRequested::class, function () use ($models) {
    foreach ($models as $model) {
        $model::syncIntoSandbox();
    }
});

Event::listen(MergeIntoActiveRequested::class, function () use ($models) {
    foreach ($models as $model) {
        $model::syncIntoActive();
    }
});

Event::listen(SandboxCommitted::class, function (SandboxCommitted $event) {
    // Пост-коммит логика: обновление кэшей, очередей и т.п.
    if ($event->asyncUpdater) {
        // Запустить через очередь
    } else {
        // Выполнить синхронно
    }
});
```

Порядок моделей в массиве задаёте вы (например, сначала справочники, потом связующие таблицы).

---

## Конфигурация

### Публикация файла конфигурации

Чтобы переопределить имя таблицы статуса и при необходимости префикс схемы, опубликуйте файл конфигурации песочницы:

```bash
php artisan vendor:publish --tag=sandbox-config
```

После выполнения команды в каталоге `config` вашего приложения появится файл `config/sandbox.php`.

### Параметры конфигурации

В опубликованном файле доступны следующие параметры:

| Параметр | По умолчанию | Описание |
|----------|--------------|----------|
| `table` | `sandbox_status` | Имя таблицы, в которой хранится статус сессии песочницы |
| `schema_prefix` | — | Необязательный префикс схемы для использования в приложении (например, в слушателях событий) |

### Переменные окружения

Имя таблицы можно задать через переменную окружения в опубликованном конфиге:

```php
'table' => env('SANDBOX_TABLE', 'sandbox_status'),
```

В файле `.env` укажите:

```env
SANDBOX_TABLE=sandbox_status
```

Если вы не публикуете конфиг, пакет использует значение по умолчанию `sandbox_status`.

---

## Сброс и применение конфигурации по ключу

Дополнительные методы синглтона:

- **resetSandboxConfiguration** — сбросить данные в sandbox из активных таблиц. Можно передать класс модели (массовый сброс через `syncIntoSandbox()`) или экземпляр (одна запись). Модель должна использовать трейт `HasSandbox` с методом `syncIntoSandbox()`.
- **applySandboxConfiguration** — применить конфиг по ключу: данные передаются в ваш applicator (`SandboxConfigApplicatorInterface`). Реализацию по умолчанию (no-op) можно заменить своей в контейнере.

```php
$sandbox->resetSandboxConfiguration(Category::class);        // вся таблица
$sandbox->resetSandboxConfiguration($category);              // одна запись
$sandbox->applySandboxConfiguration('categories', ['display_name' => 'Новое']);
```

`resetSandboxConfiguration` доступен для моделей с методом `syncIntoSandbox()` (трейт HasSandbox). `applySandboxConfiguration` вызывается по ключу: `applySandboxConfiguration(string $configKey, array $config)`.

---

## Sandbox: статус и фасад

Получить синглтон: `app(Sandbox::class)` или фасад `\Packages\Sandbox\Facades\Sandbox`.

```php
$sandbox = app(Sandbox::class);

$status = $sandbox->status();
$status?->isFree();
$status?->isLocked();
$status?->isOwnedBy($userId);
$status?->toStatusArray();  // для API
```

### Статусы используют Enum (вместо магических чисел)

Модель `SandboxStatus` использует enum `Packages\Sandbox\Enums\SandboxStatus`:

```php
use Packages\Sandbox\Enums\SandboxStatus;

// Значения enum:
SandboxStatus::Free   // = 0: песочница свободна
SandboxStatus::Locked // = 1: песочница заблокирована (пользователь редактирует)
SandboxStatus::Saved  // = 2: песочница сохранена (не коммичена)

// Проверка статуса:
$status = $sandbox->status();
if ($status->status === SandboxStatus::Locked) {
    echo "Редактирует: {$status->user_id}";
}

// Человеческое описание:
echo $status->status->label();       // "Locked"
echo $status->status->description(); // "Sandbox is locked (user is editing)"
```

`$userId` в `open()`/`close()` — int или string (UUID). При ошибках — `SandboxException` (коды 20605, 20606, 20626). Чтобы вызывать как `Sandbox::open()`, добавьте в `config/app.php` алиас: `'Sandbox' => \Packages\Sandbox\Facades\Sandbox::class`.

---

## 🚀 Developer Experience: Удобный API

### Fluent Interface

Используйте красивый fluent API вместо набора методов:

```php
// Старый способ (все еще работает)
$sandbox = app(Sandbox::class);
$sandbox->open(1);
$sandbox->resetSandboxConfiguration(Category::class);
$sandbox->close(1, result: 1, note: 'Updated');

// Новый способ (fluent interface)
Sandbox::for(1)
    ->open()
    ->apply(Category::class)
    ->commit(note: 'Updated');

// Или откат
Sandbox::for(1)->rollback();

// Или просто сохранить
Sandbox::for(1)->save();

// Если вы в контексте авторизованного пользователя, используйте Sandbox::me()
// Автоматически использует Auth::user()->id
Sandbox::me()
    ->open()
    ->apply(Category::class)
    ->commit(note: 'Updated by current user');
```

Методы builder: `open()`, `commit()`, `rollback()`, `save()`, `apply()`, `reset()`, `status()`.

Макросы: `Sandbox::for($userId)`, `Sandbox::me()` (для текущего пользователя из Auth::user()).

### Artisan Commands

Управляйте sandbox через CLI:

```bash
# Открыть песочницу (с явным userId или использует Auth::user())
php artisan sandbox:open 1 --note="Editing config"
php artisan sandbox:open     # Использует текущего авторизованного пользователя

php artisan sandbox:open 2 --force  # Force если заблокирована другим пользователем

# Закрыть с результатом (--result значение: 0=откат, 1=коммит, 2=сохранить)
php artisan sandbox:close 1 --result=1        # Коммит (SandboxStatus::Locked)
php artisan sandbox:close 1 --result=0        # Откат (SandboxStatus::Free)
php artisan sandbox:close 1 --result=2        # Сохранить (SandboxStatus::Saved)
php artisan sandbox:close 1 --result=1 --async # Async обновления
php artisan sandbox:close     # Закрывает текущего пользователя если открыт

# Проверить статус
php artisan sandbox:status
php artisan sandbox:status --details           # Детальная информация (включает enum name)
```

### Testing Helpers

Для удобства при тестировании используйте `SandboxTestHelpers`:

```php
use Packages\Sandbox\Testing\SandboxTestHelpers;

class ConfigControllerTest extends TestCase
{
    use SandboxTestHelpers;

    public function testCanEditConfig(): void
    {
        // Открыть песочницу с явным userId
        $this->openSandbox(userId: 1, note: 'Testing');

        // ... make assertions
        $this->assertSandboxLocked(userId: 1);

        // Отправить данные
        $this->postJson('/api/config', [
            'key' => 'app_name',
            'value' => 'New App',
        ]);

        // Коммитить или откатить
        $this->commitSandbox(userId: 1, note: 'Committed');
        $this->assertSandboxFree();

        // Или откатить
        $this->rollbackSandbox(userId: 1);
        $this->assertSandboxFree();

        // Или сохранить
        $this->saveSandbox(userId: 1);
        $this->assertSandboxSaved();
    }

    public function testCanEditWithCurrentUser(): void
    {
        $user = $this->actingAs(User::factory()->create());

        // Если вы в контексте авторизованного пользователя, можно не указывать userId
        $this->openSandbox();  // Автоматически использует Auth::user()
        
        // ... make changes
        
        $this->assertSandboxLocked();  // Проверяет текущего пользователя
        $this->commitSandbox();        // Коммитит текущего пользователя
        $this->assertSandboxFree();
    }

    public function testCanApplySandbox(): void
    {
        $this->openSandbox(userId: 1);

        $this->useSandbox(Category::class);
        // Now queries use sandbox table

        $this->useActive(Category::class);
        // Now queries use active table

        $this->applySandbox(Category::class);
        // Refresh sandbox data from active
    }
}
```

**Методы хелпера:**
- `openSandbox(userId, force?, note?)`
- `commitSandbox(userId, note?, async?)`
- `rollbackSandbox(userId, note?)`
- `saveSandbox(userId, note?)`
- `assertSandboxFree()`
- `assertSandboxLocked(userId)`
- `assertSandboxSaved()`
- `getSandboxStatus()`
- `useSandbox(model)`
- `useActive(model)`
- `applySandbox(model)`

---

## Трейт HasSandbox (подробнее)

### Подключение трейта

Добавьте трейт `HasSandbox` к модели:

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

### Свойства трейта

При необходимости переопределите эти статические свойства в модели:

| Свойство | По умолчанию | Описание |
|----------|--------------|----------|
| `$sandboxTablePostfix` | `'_sb'` | Постфикс, добавляемый к имени активной таблицы (итоговая sandbox-таблица: активная таблица + постфикс) |
| `$sandboxPrimaryKey` | `null` | Имя колонки первичного ключа; `null` — используется ключ модели. Для pivot-таблиц можно задать массив: `['term_id', 'category_id']` |
| `$sandboxTrackChangeColumn` | `'change_date'` | Колонка, по которой определяется, нужно ли обновлять строку при синхронизации; `null` — обновлять все строки по ключу. Для таблиц без неё переопределите `getSandboxTrackChangeColumn()` → `null` |

### Запросы к sandbox и активной таблице

Чтобы выполнять запросы к нужной таблице без смены таблицы по умолчанию, используйте scope `sandbox` и `active`:

```php
// Запрос к sandbox-таблице
Category::sandbox()->where('enabled', true)->get();

// Явный запрос к активной таблице
Category::active()->get();
```

Чтобы «переключить» модель на sandbox-таблицу по умолчанию (например, в рамках запроса, когда активен режим песочницы), вызовите статические методы:

```php
Category::useSandboxTable();
Category::useActiveTable();
// Теперь getTableForQuery() возвращает sandbox-таблицу.
// Переопределите getTable() в модели через getTableForQuery(), если нужно, чтобы обычные запросы шли в sandbox.

Category::useActiveTable();
```

### Методы трейта

- `getActiveTable()` — получить имя активной таблицы
- `getSandboxTable()` — получить имя sandbox-таблицы
- `getSandboxPrimaryKey()` — получить первичный ключ (строка или массив для составного ключа)
- `scopeSandbox($query)` — scope для запросов к sandbox-таблице
- `scopeActive($query)` — scope для запросов к активной таблице
- `useSandboxTable()` / `useActiveTable()` — переключение таблицы для `getTableForQuery()`

### Синхронизация (active ↔ sandbox)

Трейт предоставляет статические методы, которые выполняют синхронизацию на основе таблиц и ключа модели:

- **`Model::syncIntoSandbox()`** — копирование из активной таблицы в sandbox (удаление лишних в sandbox, обновление по ключу, вставка недостающих).
- **`Model::syncIntoActive()`** — копирование из sandbox в активную таблицу (та же логика в обратную сторону).

При синхронизации используется метод модели `getSandboxSyncColumns()`: по умолчанию возвращаются колонки из первой строки активной таблицы. Переопределите этот метод в модели, чтобы задать явный список колонок или когда таблица может быть пустой.

---

### Таблица `sandbox_status`

Одна строка на приложение (таблица без первичного ключа или с константным id). Поля:

| Поле | Тип | Описание |
|------|-----|----------|
| `status` | int | 0 — свободна, 1 — занята, 2 — сохранена без коммита |
| `last_operation` | int? | 0 — откат, 1 — коммит, 2 — сохранение без коммита |
| `note` | string? | Заметка |
| `change_date` | datetime | Время последнего изменения статуса |
| `user_id` | string? | Кто держит сессию (int или UUID) |
| `change_id` | int | Счётчик изменений (для инвалидации кэша и т.п.) |
| `send_date` | datetime? | Дата последнего коммита |

Модель **SandboxStatus**: методы `isFree()`, `isLocked()`, `isSaved()`, `isOwnedBy($userId)`, `toStatusArray()`.

---

## Тестирование

### Unit и Feature тесты

Запуск тестов:

```bash
composer test
```

### Мутационное тестирование

Пакет использует [Infection](https://infection.github.io/) для мутационного тестирования, которое проверяет качество тестов, внося небольшие изменения (мутации) в код и проверяя, обнаруживают ли тесты эти изменения.

**Требования:** Для работы мутационного тестирования необходим драйвер покрытия кода:
- **pcov** (рекомендуется) — быстрый и легкий
- **xdebug** — более медленный, но более функциональный  
- **phpdbg** — встроенный в PHP (менее надежный)

### Установка pcov (рекомендуется)

**Через PECL:**
```bash
pecl install pcov
```

**Через Homebrew на macOS:**
```bash
brew install pcov
```

После установки добавьте в `php.ini`:
```ini
extension=pcov.so
```

Проверка установки:
```bash
php -m | grep pcov
```

### Запуск мутационного тестирования

После установки драйвера покрытия:

```bash
composer test:mutation
```

**Примечание:** Если драйвер покрытия не установлен, Infection выдаст ошибку с инструкциями по установке. Рекомендуется установить pcov для оптимальной производительности.

Конфигурация находится в файле `infection.json`. По умолчанию установлены следующие пороги:

- **MSI (Mutation Score Indicator)**: минимум 70%
- **Covered MSI**: минимум 80%

Результаты сохраняются в файлы:
- `infection.log` — подробный лог
- `infection-summary.log` — краткая сводка
- `infection-results.json` — результаты в формате JSON
- `infection-per-mutator.log` — результаты по каждому мутатору

---

## Ограничения и предупреждения

- **Один sandbox на приложение** — таблица статуса одна, глобальная сессия редактирования общая для всех пользователей (блокировка по `user_id` при открытии/закрытии).
- **`useSandboxTable()` — статический флаг** — действует на класс модели в рамках одного запроса. В одном запросе нельзя «часть запросов в active, часть в sandbox» без явного переключения до/после; в очередях и воркерах контекст не передаётся — при необходимости передавайте явно, что работа идёт с sandbox, и вызывайте `Model::useSandboxTable()` в начале джоба.
- **Асинхронные задачи** — при коммите событие `SandboxCommitted` получает `asyncUpdater`; используйте его для запуска обновления кэшей/очередей. В самих джобах контекст «кто открыл песочницу» не сохраняется.
