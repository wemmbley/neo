# Network: Cookie & Session

## Cookie

`Neo\Network\Cookie\Cookie` is a facade over `CookieManager`.

Incoming cookies are read through `RequestContract::cookies()`. Outgoing cookies are queued on `ResponseContract::withCookie()`. Cookie code does not read `$_COOKIE` and does not call `setcookie()`.

## Session

`Neo\Network\Session\SessionManager` is a facade over `SessionManagerService`.

Available drivers:

- `database` — production default, uses `DatabaseManager`.
- `file` — stores session files through `Storage`.
- `array` — process-memory storage for tests and debugging.

The database driver expects a `sessions` table with `id` (string, primary key), `payload` (text), and `last_activity` (integer). The application migration is `app/Database/Migrations/Sessions.php`.

Session data is JSON by default. With `session.encrypt=true`, the complete payload is encrypted through `CryptManager` before it reaches the driver.

## HTTP integration seam

HTTP is not implemented yet. This package introduces only the contracts required by Cookie and Session:

- `RequestContract`
- `ResponseContract`
- `RequestContextContract`

`FiberRequestContext`, `ArrayRequest`, and `ArrayResponse` are temporary adapters for development and tests. A real HTTP runtime must enter the context at request start and leave it at request end. A Swoole adapter must implement `RequestContextContract` using coroutine-local context; it must not share mutable request state across the worker process.

The current `HttpServiceProvider` binds the Fiber-based development context. A production HTTP provider should replace that binding.

## Request lifecycle

1. HTTP adapter creates Request and Response objects and enters request context.
2. Session reads its ID from the incoming cookie on first access.
3. Session loads data through the configured driver.
4. Application code reads and modifies session data.
5. HTTP middleware calls `SessionManager::save()` before the response is sent.
6. CookieManager queues the session cookie on Response.
7. HTTP adapter serializes queued cookies into `Set-Cookie` headers and leaves request context.

No PHP native session functions or `$_SESSION` are used.
