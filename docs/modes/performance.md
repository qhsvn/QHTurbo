# QHturbo Performance

**English** · [Tiếng Việt](performance.vi.md)

**Goal:** much higher speed than the "restart the application on every request" model, thanks to:

- **Long-running workers:** the application boots **once** per process, then serves thousands of requests.
- **Event loop + async I/O** (Fiber, PHP ≥ 8.1): while one request waits on I/O (MySQL, Redis, API calls, internal services), the worker serves another request.
- **Non-blocking Redis and MySQL clients** with connection pooling (section 5).
- **Laravel and Symfony adapters**: run existing applications without code changes (section 6).
- More aggressive configuration: OPcache CLI, `validate_timestamps=0`, JIT tracing.

In return, the application **must follow the state management rules** between requests (section 3).

| | |
|---|---|
| PHP version | **8.1 or later** (requires Fiber). PHP 7.4/8.0 use Compatible mode |
| Execution model | nginx (reverse proxy) → `qhturbo-serve` (master + N workers) |
| Source code | `runtime/` (Composer package `qhturbo/runtime`, no dependencies, also runs without Composer) |
| Tests | `php runtime/tests/run.php`: 34 tests (event loop, async, pool, HTTP, worker respawn, reload including a broken deploy, state rules, Redis, MySQL)<br>`php runtime/tests/frameworks.php`: 6 tests on real Laravel 13 and Symfony 8.1 |

## 1. Measured results

**Real frameworks** (4 vCPU, PHP 8.5.12, production configuration, nginx + wrk on the same machine; `benchmarks/web/framework.sh`):

| Application / route | Compatible (FPM) | Performance (worker) | Speedup |
|---|---|---|---|
| Laravel 13 `/up` (health check, no session) | 353 req/s, p50 89 ms | **5,150 req/s, p50 5.8 ms** | **14.6×** |
| Laravel 13 `/` (welcome page, Blade, Redis session) | 288 req/s | **1,924 req/s** | **6.7×** |
| Laravel 13 JSON route (web middleware, Redis session) | 319 req/s | **2,295 req/s** | **7.2×** |
| Symfony 8.1 JSON route | 1,737 req/s, p50 17.7 ms | **11,160 req/s, p50 2.5 ms** | **6.3×** |
| RAM (PSS) Laravel / Symfony | 98–101 / 61 MiB | 123–151 / 77 MiB | workers keep the framework loaded |

Reports: `results/web/2026-10-22-laravel13-fpm-vs-workers.md`, `results/web/2026-10-22-symfony81-fpm-vs-workers.md`.

**MySQL I/O** (each request runs 3 queries × 20 ms; `benchmarks/web/db/run.sh`, `results/web/2026-10-22-mysql-io-async.md`):

| Configuration | req/s | p99 | RAM |
|---|---|---|---|
| FPM ×4, PDO | 51 (457 timeouts) | 2 s | 13 MiB |
| FPM ×64, PDO | 1,011 | 163 ms | 45 MiB |
| Worker ×4, PDO, concurrency 1 | 51 (300 timeouts) | 2 s | 36 MiB |
| **Worker ×4, async MySQL, concurrency 64** | **1,724** | **115 ms** | 49 MiB |

Workers that still use blocking PDO **cannot handle I/O load**; the async driver fixes exactly this. The remaining limit is MariaDB running on the same machine.

**Simulated framework** — `results/web/2026-10-22-php8.5-modes.md`: the same simulated "framework" (300 classes, container, 40 config files, 203 routes), PHP 8.5.12, 4 vCPU, nginx + wrk running on the same machine.

| Test | Compatible (FPM) | Performance (worker) | Result |
|---|---|---|---|
| `/hello`, 4 PHP processes | 2,991 req/s, p50 10.4 ms | **23,598 req/s, p50 1.1 ms** | **7.9×** |
| `/api/users/42` (JSON) | 3,008 req/s, p50 10.4 ms | **21,266 req/s, p50 1.3 ms** | **7.1×** |
| `/io`: 3 backend calls × 20 ms | FPM ×4: 49 req/s, nearly 500 timeouts<br>FPM ×64: 939 req/s, 104 MiB | **2,389 req/s, 56 MiB** (4 workers, concurrency 64) | 2.5× vs FPM ×64, using half the RAM |
| RAM (PSS), CPU test | 30–33 MiB | 43 MiB | +10 MiB for 4 workers keeping the application loaded |
| RAM after 30 s of sustained load (~780,000 requests) | — | 54 MiB, not growing | No leak |

How to read this: the biggest gain comes from **removing the framework boot cost** on every request. Real frameworks (Laravel, Symfony) have different boot costs than the simulated test, so you must measure with your own application. `benchmarks/web/run.sh` is a template for doing that. The `/io` test is limited by the simulated backend (only 1 process).

## 2. Usage

```php
<?php // worker.php: this part runs ONCE per worker
use QHTurbo\Runtime\Http\{Request, Response, Client};
use function QHTurbo\Runtime\Async\{async, all};

$db = new PDO($dsn, $user, $pass, [PDO::ATTR_PERSISTENT => false]); // reused across requests
$config = require __DIR__ . '/config.php';

return function (Request $request) use ($db, $config): Response|array|string {
    // This part runs for EACH request. Request state lives in $request.
    if ($request->path() === '/dashboard') {
        // 3 internal service calls run in parallel (needs --concurrency > 1 to take full advantage)
        [$a, $b, $c] = all([
            async(fn () => Client::get('http://stats.internal/a')['body']),
            async(fn () => Client::get('http://stats.internal/b')['body']),
            async(fn () => Client::get('http://stats.internal/c')['body']),
        ]);
        return ['a' => $a, 'b' => $b, 'c' => $c];
    }
    return Response::text("Not Found\n", 404);
};
```

```bash
# development
php runtime/bin/qhturbo-serve worker.php --port=8080 --strict-state --debug
# production: 1 worker per CPU, respawn after 10,000 requests or 256 MiB
qhturbo-serve worker.php --workers=4 --concurrency=1 --max-requests=10000 --memory-limit=256M
kill -HUP <master-pid>      # deploy: replace workers one by one, no dropped requests
```

On reload (`SIGHUP`): OPcache entries for loaded files are invalidated so new workers read the new code (even with `opcache.validate_timestamps=0`). Old workers are stopped only **after** the new workers have finished booting; if the new code is broken (for example a syntax error), the reload stops, the log records `reload aborted`, and the old workers keep serving the old code. Old workers have up to 60 seconds to finish in-flight requests. After many reloads, OPcache memory fills up with old versions; do a full restart now and then (`systemctl restart`).

Systemd: `qhturbo-php85-worker@<name>.service` and the file `/etc/qhturbo/worker-<name>.env` (installed by `scripts/install.sh`). nginx sits in front as the reverse proxy (TLS, HTTP/2, static files, compression):

```nginx
upstream app { server 127.0.0.1:8080; keepalive 64; }
location / { proxy_http_version 1.1; proxy_set_header Connection ""; proxy_set_header Host $host;
             proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_pass http://app; }
```

| Option | Default | Meaning |
|---|---|---|
| `--workers=N` | 0 | Number of processes (0 = a single process, no pcntl needed, no automatic respawn) |
| `--concurrency=C` | 1 | Number of requests interleaved within one worker. **1 = safe for any code** |
| `--max-requests=N` | 10000 | Respawn a worker after N requests (contains gradual leaks) |
| `--memory-limit=256M` | 256M | Respawn a worker when its RAM exceeds the threshold |
| `--superglobals` | off | Populate `$_GET/$_POST/$_COOKIE/$_SERVER` for legacy code (requires `--concurrency=1`) |
| `--strict-state` | off | State rule violations return 500 and are logged (use in dev/staging) |
| `--state-check-every=N` | 100 | Check static properties/RAM every N requests |
| `--framework=laravel\|symfony` | — | Run a Laravel/Symfony project directory (section 6). Requires `--concurrency=1` |
| `--max-body=8M` | 8M | Maximum request size (including uploads). Accepts `512K`, `8M`, `8MB`, `1G` |
| `--max-connections=N` | 512 | Maximum open connections per worker (at most 1000, a `stream_select` limit). When full, the worker temporarily stops accepting new connections; other workers or the kernel queue hold them |

## 3. State management rules (mandatory)

In FPM, everything disappears on its own when the request ends. In a worker, **whatever survives a request "leaks" into the next request**, even to a different user. The rules:

| # | Rule | Runtime support |
|---|---|---|
| R1 | **Do not store request data in global variables, static properties or static variables.** Put it in the `Request` or in an object created fresh for each request | New global variables are **deleted** and logged; static properties that keep growing trigger a warning |
| R2 | Services holding per-request state (current user, unit of work…) must implement `ResetInterface` | `reset()` is called after **every** request |
| R3 | **No `exit()`/`die()`**: it kills the worker. Return a `Response` | The supervisor restarts the worker, but the in-flight request fails |
| R4 | **Do not use `header()`, `setcookie()`, `echo` to return output**: set headers/body on the `Response` | `qht-doctor --scan` lists the places to fix |
| R5 | Do not change `ini_set`, `date_default_timezone_set`, `setlocale` per request | Changed ini settings are **restored** after the request |
| R6 | Output buffers must be closed | Buffers left open are cleaned up and logged |
| R7 | DB/Redis connections: open once and reuse. Handle lost connections (reconnect), and **never leave a transaction open into the next request** | The runtime's MySQL/Redis clients (section 5): pooling, idle connection checks, rollback of open transactions |
| R8 | With `--concurrency > 1`: all state shared between interleaved in-flight requests must be **safe**. No superglobals. **Blocking calls (PDO, `file_get_contents` on a URL, `sleep`) block the whole worker**, so use only async I/O | `--superglobals` is rejected when concurrency > 1 |
| R9 | Code that registers handlers per request (`set_error_handler`, `register_shutdown_function`) will stack them up | `qht-doctor --scan` warns |

**Migration path for moving an application to Performance:**

1. `php qht-doctor.php --scan=/var/www/app`: lists violations of R1–R9 (`vendor/` is skipped).
2. Run staging with `--concurrency=1 --superglobals --strict-state`: legacy code needs few changes, and violations show up immediately as 500 errors with a log entry.
3. Fix all violations, turn off `--superglobals` and switch to using `Request`.
4. Only raise `--concurrency` once I/O is async (R8).
5. Production: start with a portion of the traffic (canary), keeping FPM (Compatible) as the fallback.

**Not suitable for Performance mode (just use Compatible):** WordPress and its plugins (heavy use of globals), code that uses `exit()` for control flow, applications whose source code you do not control.

## 4. Current limitations

- HTTP/1.1 is implemented in PHP: no TLS, HTTP/2 or chunked request bodies (let nginx in front handle those). `multipart/form-data` uploads are loaded into RAM, so they are bounded by `--max-body`.
- `move_uploaded_file()` and `is_uploaded_file()` always return `false` outside PHP-FPM (the temporary files are not created by PHP's upload handling). Legacy code using `--superglobals` must switch to `rename($_FILES[...]['tmp_name'], $dest)`; `qht-doctor --scan` points these out. Laravel/Symfony `UploadedFile` works normally.
- Responses are built entirely in RAM before being sent, including Symfony/Laravel `StreamedResponse` and `BinaryFileResponse`. Let nginx serve large downloads (static files or `X-Accel-Redirect`).
- With `--concurrency > 1`, reset hooks and state cleanup run when the worker has no in-flight requests; under sustained load, every 64 requests the worker pauses accepting new requests to clean up.
- MySQL client: new connections are opened in blocking mode (mysqlnd has no async connect); thanks to pooling this costs only once per connection. No server-side prepared statements yet: parameters are escaped according to the charset, like PDO MySQL's default "emulated prepares" mode. Redis: no Pub/Sub, Cluster or Sentinel yet.
- PDO/Eloquent/Doctrine still **block**. Laravel/Symfony applications running with `--concurrency=1` are still 6–15× faster by removing the boot cost; to take advantage of parallel I/O, use the async clients in I/O-heavy sections.
- The Laravel adapter mimics Octane's state cleanup but does not yet cover every case. Third-party packages that keep state in statics or singletons can still leak; run staging with `--strict-state`, `--state-check-every=1` and keep `--max-requests`.
- Mature solutions worth considering for large production deployments: FrankenPHP (worker mode), RoadRunner, Swoole/OpenSwoole, Laravel Octane. QHturbo Performance runs without any external extension, and is easier to read and control.

## 5. Non-blocking Redis and MySQL

```php
use QHTurbo\Runtime\Mysql\Client as Mysql;
use QHTurbo\Runtime\Redis\Client as Redis;
use function QHTurbo\Runtime\Async\{all, async};

$db    = new Mysql('mysql://app:secret@127.0.0.1:3306/shop?pool=16'); // created once per worker
$redis = new Redis('redis://:secret@127.0.0.1:6379/0', poolSize: 16);

return function (Request $r) use ($db, $redis): array {
    if (($cached = $redis->get('home')) !== null) {
        return json_decode($cached, true);
    }
    // 3 queries run in parallel on 3 pooled connections
    [$users, $orders, $stats] = all([
        async(fn () => $db->query('SELECT id, name FROM users WHERE id IN (?)', [[1, 2, 3]])->rows),
        async(fn () => $db->query('SELECT * FROM orders WHERE created_at > ?', [new DateTime('-1 day')])->rows),
        async(fn () => $db->query('SELECT COUNT(*) FROM visits')->value()),
    ]);
    $redis->set('home', json_encode($data = compact('users', 'orders', 'stats')), 'EX', 30);
    return $data;
};
```

| Feature | Redis | MySQL / MariaDB |
|---|---|---|
| Does not block the worker | Async socket (RESP2) | `MYSQLI_ASYNC` + `mysqli_poll` (mysqlnd handles authentication, including `caching_sha2_password`, TLS and charset) |
| Connection pool | Yes: connection limit, requests wait when the pool is exhausted, broken connections are discarded automatically | Yes |
| Commands | Any command via `__call`/`command()`, `pipeline()` | `query($sql, $params)` with `?`, arrays for `IN (?)`, `transaction(fn)`, `withConnection(fn)` |
| Errors | `WRONGTYPE`-style errors → `RedisException`, the connection remains usable | `QueryException` (MySQL code, e.g. 1062); on timeout the connection is discarded |
| State safety (R7) | — | A transaction still open when the connection is returned is **rolled back automatically** and logged |
| Recovery | Server closes the connection → the connection is discarded, the next call opens a new one | "Server has gone away" on an idle connection → automatic single retry |

Outside a fiber (for example in FPM or a CLI script), each command runs as an ordinary blocking call.

## 6. Running Laravel and Symfony

```bash
# No application code changes needed. Tested: Laravel 13, Symfony 8.1 (written for Laravel 9+ / Symfony 5.4+)
qhturbo-serve /var/www/laravel --framework=laravel --workers=4 --max-requests=5000
qhturbo-serve /var/www/symfony --framework=symfony --workers=4
```

**Laravel:** the framework boots once (env, config, providers, routes). After **every** request, the adapter cleans up the state Laravel keeps in long-lived services (similar to how Laravel Octane does it):

| State | Handling | Tested |
|---|---|---|
| Authenticated user (guard) | `forgetGuards()` | ✔ the previous request's user does not leak into the next request |
| Session | `flush()` + `regenerate()` on the shared store (otherwise session data would mix between two users) | ✔ two browsers have separate sessions |
| Queued cookies | `flushQueuedCookies()` | ✔ |
| Open DB transaction | rollback + log (R7) | ✔ |
| Config changed at runtime | restored | ✔ |
| Locale | reset `app.locale` | ✔ |
| Scoped instances, view sections, log context, Str cache, query log | cleared | |
| Uploads, JSON body, 404, 500 (no error details exposed) | — | ✔ |

The adapter warns at boot if `SESSION_DRIVER`/`CACHE_STORE` is `array`: that driver keeps data in the worker's RAM, so it grows forever and is not shared between workers. While developing the adapter, we measured and identified this as the cause of a "leak" (about 700 bytes/request). With `file` or `redis` sessions: growth of about 2 bytes/request over 20,000 requests, i.e. no leak.

**Symfony:** the kernel boots once. Symfony itself resets services tagged `kernel.reset` (`ResetInterface`) between requests (tested). The adapter sets `APP_RUNTIME_MODE=web=1&worker=1`; otherwise, because `PHP_SAPI` is `cli`, Symfony would print **exception details into 500 responses** even in the prod environment. This bug was found and fixed during testing.

**Recommended configuration:** sessions and cache in Redis or files (not `array`), `php artisan optimize` / `cache:warmup`, and trusted proxies (`trustProxies`) since requests come from nginx.
