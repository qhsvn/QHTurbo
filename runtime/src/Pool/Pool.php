<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Pool;

use QHTurbo\Runtime\Async\Future;
use QHTurbo\Runtime\Async\TimeoutException;

use function QHTurbo\Runtime\Async\await;
use function QHTurbo\Runtime\Async\timeout;

/**
 * Bounded pool of reusable connections shared by all requests of a worker.
 * When every connection is busy, callers wait (in their fiber) until one is
 * released, so a worker never opens more than $maxSize connections.
 *
 * @template T of object
 */
final class Pool
{
    /** @var list<T> */
    private array $idle = [];
    /** @var array<int, float> spl_object_id => time the connection went idle */
    private array $idleSince = [];
    /** @var list<Future> */
    private array $waiters = [];
    private int $size = 0;
    private bool $closed = false;

    /**
     * @param \Closure(): T                 $factory  opens a new connection
     * @param \Closure(T): void|null        $closer   closes a connection
     * @param \Closure(T, float): bool|null $check    is an idle connection still usable? (receives idle seconds)
     */
    public function __construct(
        private readonly \Closure $factory,
        private readonly int $maxSize = 10,
        private readonly ?\Closure $closer = null,
        private readonly ?\Closure $check = null,
        private readonly float $acquireTimeout = 10.0,
    ) {
        if ($maxSize < 1) {
            throw new \InvalidArgumentException('Pool size must be >= 1');
        }
    }

    /** @return T */
    public function acquire(): object
    {
        if ($this->closed) {
            throw new \LogicException('Pool is closed');
        }
        while ($this->idle) {
            $conn = array_pop($this->idle);
            $idleFor = microtime(true) - ($this->idleSince[spl_object_id($conn)] ?? microtime(true));
            unset($this->idleSince[spl_object_id($conn)]);
            if ($this->check === null || ($this->check)($conn, $idleFor)) {
                return $conn;
            }
            $this->discard($conn);
        }
        if ($this->size < $this->maxSize) {
            $this->size++; // reserve the slot before the (possibly async) connect
            try {
                return ($this->factory)();
            } catch (\Throwable $e) {
                $this->size--;
                $this->wakeOne();
                throw $e;
            }
        }
        $wait = new Future();
        $this->waiters[] = $wait;
        try {
            $conn = await(timeout($wait, $this->acquireTimeout));
        } catch (TimeoutException $e) {
            $this->waiters = array_values(array_filter($this->waiters, static fn ($w) => $w !== $wait));
            throw new TimeoutException(sprintf('No connection available within %.1f s (pool size %d)', $this->acquireTimeout, $this->maxSize), 0, $e);
        }
        // null = a slot was freed (connection discarded): open a new one.
        return $conn ?? $this->acquire();
    }

    /** @param T $conn */
    public function release(object $conn): void
    {
        if ($this->closed) {
            $this->discard($conn);
            return;
        }
        while ($this->waiters) {
            $waiter = array_shift($this->waiters);
            if (!$waiter->isDone()) {
                $waiter->resolve($conn); // hand over directly
                return;
            }
        }
        $this->idleSince[spl_object_id($conn)] = microtime(true);
        $this->idle[] = $conn;
    }

    /** Drop a broken connection and free its slot. @param T $conn */
    public function discard(object $conn): void
    {
        $this->size = max(0, $this->size - 1);
        unset($this->idleSince[spl_object_id($conn)]);
        try {
            $this->closer && ($this->closer)($conn);
        } catch (\Throwable) {
        }
        $this->wakeOne();
    }

    /**
     * Run $fn with a connection; it is released afterwards, or discarded if
     * $fn throws and $isBroken says the connection is unusable (default:
     * any exception discards it).
     *
     * @template R
     * @param callable(T): R $fn
     * @param (callable(\Throwable, T): bool)|null $isBroken
     * @return R
     */
    public function use(callable $fn, ?callable $isBroken = null): mixed
    {
        $conn = $this->acquire();
        try {
            $result = $fn($conn);
        } catch (\Throwable $e) {
            ($isBroken === null || $isBroken($e, $conn)) ? $this->discard($conn) : $this->release($conn);
            throw $e;
        }
        $this->release($conn);
        return $result;
    }

    /** @return array{size: int, idle: int, busy: int, waiting: int, max: int} */
    public function stats(): array
    {
        return ['size' => $this->size, 'idle' => count($this->idle), 'busy' => $this->size - count($this->idle),
            'waiting' => count($this->waiters), 'max' => $this->maxSize];
    }

    public function close(): void
    {
        $this->closed = true;
        foreach ($this->idle as $conn) {
            $this->discard($conn);
        }
        $this->idle = [];
    }

    private function wakeOne(): void
    {
        while ($this->waiters) {
            $waiter = array_shift($this->waiters);
            if (!$waiter->isDone()) {
                $waiter->resolve(null);
                return;
            }
        }
    }
}
