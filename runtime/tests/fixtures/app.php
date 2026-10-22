<?php

declare(strict_types=1);

// Test application for runtime/tests/run.php.

use QHTurbo\Runtime\Http\Request;
use QHTurbo\Runtime\Http\Response;
use QHTurbo\Runtime\State\ResetInterface;

use function QHTurbo\Runtime\Async\delay;

ini_set('date.timezone', 'UTC');

final class LeakyCache
{
    /** @var list<string> */
    public static array $items = [];
}

/** Request-scoped service: must be reset after each request. */
final class CurrentUser implements ResetInterface
{
    public string $name = 'guest';

    public function reset(): void
    {
        $this->name = 'guest';
    }
}

final class App implements ResetInterface
{
    public function __construct(private CurrentUser $user)
    {
    }

    public function reset(): void
    {
        $this->user->reset();
    }

    public function handle(Request $r): Response|string|array
    {
        switch ($r->path()) {
            case '/hello':
                return Response::text('hello ' . ($r->query['name'] ?? 'world'));
            case '/echo':
                return Response::json($r->parsedBody());
            case '/pid':
                return Response::text((string) getmypid());
            case '/slow':
                delay(0.2);
                return Response::text('done');
            case '/throw':
                throw new LogicException('secret');
            case '/leak-global':
                $GLOBALS['qht_leak'] = 'x';
                return 'leaked';
            case '/read-global':
                return isset($GLOBALS['qht_leak']) ? 'present' : 'absent';
            case '/leak-static':
                LeakyCache::$items[] = str_repeat('x', 100);
                return 'ok';
            case '/legacy':
                return ['get' => $_GET, 'post' => $_POST, 'cookie' => $_COOKIE, 'method' => $_SERVER['REQUEST_METHOD'] ?? null];
            case '/set-user':
                $this->user->name = $r->query['u'] ?? 'x';
                return 'set';
            case '/user':
                return Response::text($this->user->name);
            case '/change-tz':
                ini_set('date.timezone', 'Asia/Ho_Chi_Minh');
                return 'changed';
            case '/tz':
                return Response::text((string) ini_get('date.timezone'));
        }
        return Response::text("Not Found\n", 404);
    }
}

return new App(new CurrentUser());
