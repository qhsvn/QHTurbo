<?php

declare(strict_types=1);

// Synthetic framework bootstrap shared by the FPM front controller and the
// QHturbo worker: autoloader, container with N service factories, config
// merge and route compilation. Returns the application object.

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Bench\\Services\\')) {
        require __DIR__ . '/generated/Services/' . substr($class, 15) . '.php';
    }
});

final class BenchApp
{
    /** @var array<string, \Closure> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];
    /** @var array<string, mixed> */
    private array $config = [];
    /** @var list<array{0: string, 1: string}> */
    private array $routes = [];

    public function __construct(string $dir)
    {
        $n = (int) file_get_contents("$dir/generated/COUNT");
        for ($i = 0; $i < $n; $i++) {
            $class = "Bench\\Services\\Service$i";
            $this->factories[$class] = fn () => new $class($i > 0 ? $this->get("Bench\\Services\\Service" . ($i - 1)) : null);
        }
        foreach (glob("$dir/generated/config/*.php") as $file) {
            $this->config[basename($file, '.php')] = require $file;
        }
        for ($r = 0; $r < 200; $r++) {
            $this->routes[] = ["#^/module$r/(\\d+)$#", "module$r"];
        }
        $this->routes[] = ['#^/hello$#', 'hello'];
        $this->routes[] = ['#^/api/users/(\d+)$#', 'user'];
        $this->routes[] = ['#^/io$#', 'io'];
        // Frameworks resolve many services while booting; so do we.
        for ($i = 0; $i < $n; $i += 3) {
            $this->get("Bench\\Services\\Service$i");
        }
    }

    public function get(string $id): object
    {
        return $this->instances[$id] ??= ($this->factories[$id])();
    }

    /**
     * @param callable(string): string $backendCall fetches a backend URL
     * @return array{0: int, 1: string, 2: string} status, content type, body
     */
    public function handle(string $path, callable $backendCall): array
    {
        foreach ($this->routes as [$regex, $name]) {
            if (preg_match($regex, $path, $m)) {
                switch ($name) {
                    case 'hello':
                        return [200, 'text/plain', "Hello\n"];
                    case 'user':
                        $svc = $this->get('Bench\\Services\\Service42');
                        $user = [
                            'id' => (int) $m[1],
                            'name' => 'User ' . $m[1],
                            'score' => $svc->compute((int) $m[1]),
                            'service' => $svc->describe(),
                            'flags' => array_slice($this->config['section1'], 0, 5),
                            'friends' => array_map(static fn ($i) => ['id' => $i, 'name' => "Friend $i"], range(1, 20)),
                        ];
                        return [200, 'application/json', json_encode($user)];
                    case 'io':
                        // Three calls to a backend service that takes 20 ms each.
                        $results = $backendCall('http://127.0.0.1:8095/a');
                        return [200, 'application/json', json_encode(['backend' => $results])];
                    default:
                        return [200, 'text/plain', "$name\n"];
                }
            }
        }
        return [404, 'text/plain', "Not Found\n"];
    }
}

return new BenchApp(__DIR__);
