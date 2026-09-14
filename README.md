# Sandbox

[![Tests](https://github.com/cosmira/sandbox/actions/workflows/phpunit.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/phpunit.yml)
[![Code Coverage](https://github.com/cosmira/sandbox/actions/workflows/coverage.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/coverage.yml)
[![Markdown](https://github.com/cosmira/sandbox/actions/workflows/markdown.yml/badge.svg)](https://github.com/cosmira/sandbox/actions/workflows/markdown.yml)

**Database-backed drafts for Laravel configuration screens.**

Edit configuration through Eloquent shadow tables, then publish, discard, or
save the draft. One user owns the editing session. Active data remains available
while the owner works on pricing rules, category trees, feature flags, or other
shared configuration.

## Requirements

- PHP 8.2 or later.
- Laravel 12 or 13, with a PHP version supported by that Laravel release.
- SQLite, PostgreSQL, or MySQL.
- An active table and a matching draft table for each registered model or table.

## Installation

```bash
composer require cosmira/sandbox:^0.1
php artisan vendor:publish --tag=sandbox-migrations
php artisan migrate
```

Publish migrations only for a new installation. For an existing shared status
schema, follow the [installation guide](docs/guide.md#installation).

Create matching shadow tables in your application's migrations. For example,
`categories` uses `categories_sb` by default. Sandbox does not create those tables.

## Quick start

Add `HasSandbox` to each configuration model:

```php
use Cosmira\Sandbox\HasSandbox;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasSandbox;

    public $timestamps = false;

    protected $table = 'categories';
}
```

Register models in an application service provider, in dependency order:

```php
use App\Models\Category;
use Cosmira\Sandbox\Facades\Sandbox;

Sandbox::models(Category::class);
```

Protect configuration routes with authentication and the `sandbox` middleware:

```php
use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'sandbox'])->group(function (): void {
    Route::resource('categories', CategoryController::class);
});
```

Controllers keep using normal Eloquent queries. The first mutating request
copies active configuration into the draft and locks it for the current user.
The application's own authorization rules still determine who may edit.

Finish the session explicitly:

```php
use Cosmira\Sandbox\Facades\Sandbox;

Sandbox::me()->commit(note: 'Publish configuration');
// Or discard changes:
Sandbox::me()->rollback(note: 'Discard draft');
// Or keep the draft for later:
Sandbox::me()->save(note: 'Continue tomorrow');
```

## Lifecycle

| Operation | Result |
| --- | --- |
| `open()` on a free sandbox | Copy active data into the draft and lock it |
| `open()` on a saved draft | Resume editing without resetting changes |
| `commit()` | Publish the draft and release the lock |
| `rollback()` | Replace the draft with active data and release the lock |
| `save()` | Keep the draft and pause editing |

The owner reads and writes a locked draft. Other users read active data and
cannot modify the locked configuration. Authenticated users can read a saved
draft; guests always read active data. Host permissions apply to every operation.

An edit includes opening, locking, and writes in one transaction. Exceptions
and HTTP responses with status 400 or higher roll it back. Completed lifecycle
events run after commit, so a listener failure cannot undo published data.

## Documentation

- [Configuration workflow and request handling](docs/guide.md#the-configuration-workflow)
- [Lifecycle API](docs/guide.md#lifecycle-api)
- [Model registration](docs/guide.md#model-registration)
- [Pivot tables, trees, and selective copying](docs/guide.md#pivot-tables-without-model-classes)
- [Model options and explicit table scopes](docs/guide.md#working-with-models)
- [Synchronization](docs/guide.md#synchronization)
- [Status and UI](docs/guide.md#status-and-ui)
- [Events](docs/guide.md#events)
- [Testing helpers](docs/guide.md#testing)
- [Changelog](CHANGELOG.md)

## Development

```bash
composer install
composer test
composer test:coverage
composer test:mutation
npx --yes markdownlint-cli2@0.23.2
```

CI runs the PHP/Laravel matrix, PostgreSQL and MySQL contracts, coding style,
mutation testing, spelling, ShellCheck, and Markdown validation. Infection
requires at least 96% MSI and covered MSI.

## Limitations

- One global configuration session per application.
- Model table switching is static; concurrent coroutine runtimes are unsupported.
- Hydrated models retain their selected table after a context ends. Do not carry
  draft models across authorization boundaries.
- Raw SQL and `DB::table()` calls do not switch tables automatically.
- Oracle native integration is unverified. A custom backend must preserve the
  enclosing transaction and enforce the same lifecycle contract.

## License

[MIT](LICENSE.md).
