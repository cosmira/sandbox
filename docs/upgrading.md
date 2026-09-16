# Compatibility with 0.1.0

Existing applications keep their public API. Models only need `use HasSandbox`;
no additional interface or configuration migration is required.

The following remain supported:

- `Sandbox::me()`, `Sandbox::for($userId)` and boolean `force` arguments.
- `new Sandbox()`, `new SandboxBuilder($userId)`, `new SandboxMiddleware()` and
  `new SandboxResolvingModels($request)`, with optional dependency injection.
- `SandboxResolvingModels::restoreActiveTables()` as a static call.
- `supportsSandboxSync()` and the existing model settings:
  `$sandboxTablePostfix`, `$sandboxPrimaryKey`, `$sandboxTrackChangeColumn`
  and `$sandboxSyncColumns`. Existing method overrides also keep working.
- `new SandboxTable('products', ['id'])`, optional named arguments and `_sb` defaults.
- Boolean arguments to registry `usingTables()` and `resolveTable()`.
- The existing `SandboxTableSynchronizer::sync()` positional and named arguments.
- Existing namespaces, Laravel convenience traits and `ConnectionInterface` signatures.

Table selection is now scoped to the application context. Use `useSandbox()`,
`useActive()`, `withSandbox()` and `withoutSandbox()` to select tables; the old
protected `$usesSandbox` implementation detail is no longer the state store.
Nested operations restore their previous selection even when callbacks throw.

## Development checks

Run `composer test:types`, `vendor/bin/pint --test`, `composer test` and
`composer test:mutation`. Mutation thresholds remain 96%.

PHPStan keeps its level and checks, with narrowly scoped exceptions for validated
structural model calls and Laravel connection methods missing from the framework's
`ConnectionInterface`. They are documented in `phpstan.neon`; user models are not
required to implement interfaces solely for static analysis.
