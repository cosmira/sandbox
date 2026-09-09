# Changelog

## 0.1.0-rc.1

This is an integration prerelease, not authorization for production rollout.
No stable release or verified Oracle integration is implied.

### Breaking Changes

- `SandboxBackend` is the complete lifecycle boundary: implement `connection`,
  `open`, `save`, `commit`, `rollback`, `reset(userId, modelOrClass)` and `status`.
  Bind an implementation instead of subclassing `Sandbox` to override storage.
- Replace `Sandbox::resetSandboxData($model)` with
  `Sandbox::reset($user, $model)` (or the explicit-owner compatibility alias).
  `Sandbox::for($user)->reset($model)` and `apply($model)` also delegate to the
  backend. Low-level model/registry synchronizers do not authorize callers.
- Replace `commit($user, $note, $asyncUpdater)` with `commit($user, $note)`.
  `SandboxCommitted` and testing helpers no longer contain `asyncUpdater`.
  The host application decides when and how to run external updaters.
- `save`, `commit`, `rollback` and `reset` require the owner of a Locked draft.
  Call `open` before changing a Saved draft. A different owner cannot roll back
  a Locked draft without an explicitly authorized `open(force: true)` first.
  Force authorization remains the application's responsibility.
- Guests read active data only. A Locked draft is readable by its owner;
  authenticated users can read Saved drafts. Host permissions still apply.
- Hydrated models retain their physical table after a context exits. Do not
  carry these instances across user authorization boundaries. New queries use
  the current context; nested contexts are restored even after exceptions.
- Synchronization updates matched rows in place, including rows with equal
  timestamps, instead of deleting/reinserting. `sandboxTrackChangeColumn` is
  validated when configured but no longer decides data equality. Matched rows
  are currently updated even when all values are equal.
- Registered models must use the backend status connection. Supply a status
  model on the desired connection to `EloquentSandboxBackend` and configure
  registered models consistently; cross-connection lifecycle writes fail.

### Transaction Contract

Opening, locking and editing share one transaction. Exceptions and middleware
responses >= 400 roll back writes and a newly acquired lock. Same-owner reopening
is a no-op. Completed transition events are dispatched after the outer commit;
a listener failure cannot roll back committed data. The caller must distinguish
post-commit failure from rejection and design external delivery accordingly.

SQLite reserves a write lock on the singleton before reading its status, including
DEFERRED transactions and PHP 8.2/8.3 where Laravel cannot select IMMEDIATE mode.
`SandboxStatusLocker` exposes the same lock primitive for custom singleton
adapters; it requires an enclosing transaction and does not authorize callers.

### Release Gates

Before creating the immutable RC tag, record successful SQLite/PostgreSQL
contracts, MySQL CI, the declared PHP/Laravel matrix, Infection with unchanged
96% thresholds, 20 fixed random-order runs, 100 repetitions of each critical
SQLite/PostgreSQL process-concurrency scenario, six seeded control defects,
and the consuming application's full regression. Unavailable gates are not
successful gates. This candidate document is not evidence that they passed.

### Limits

- Concurrent coroutine runtimes are not supported.
- Oracle native integration remains unverified. A PHP transaction cannot undo
  an internal procedure COMMIT; hierarchy refresh must remain an explicit
  refusal until a transaction-safe native path is verified.
- Existing shared singleton schemas without `id` need an adapter. Do not
  publish package status migrations into an existing legacy schema.
- Traffic Monitor completion remains in legacy `/config`, including LDAP merge,
  notifications and updater execution. Its native bidirectional integration,
  GUI database permissions and lost-response idempotency require a later
  dedicated PostgreSQL/Oracle staging gate before rollout.
