<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\EventLoop;

/**
 * Single-threaded reactor built on stream_select(): timers, deferred
 * callbacks and stream readiness watchers. One loop per process.
 */
final class Loop
{
    private static ?Loop $instance = null;

    /** @var array<string, resource> */
    private array $readStreams = [];
    /** @var array<string, callable> */
    private array $readCallbacks = [];
    /** @var array<string, resource> */
    private array $writeStreams = [];
    /** @var array<string, callable> */
    private array $writeCallbacks = [];
    /** @var array<string, array{0: float, 1: callable, 2: ?float}> id => [due, callback, interval] */
    private array $timers = [];
    private \SplPriorityQueue $timerQueue;
    /** @var list<callable> */
    private array $deferred = [];
    private int $nextId = 0;
    private int $timerSeq = PHP_INT_MAX;
    /** Cancelled timers still sitting in $timerQueue (removed lazily). */
    private int $cancelledTimers = 0;
    private bool $running = false;
    private bool $stopRequested = false;

    public function __construct()
    {
        $this->timerQueue = new \SplPriorityQueue();
        $this->timerQueue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    /** Replace the process-wide loop (used after fork and in tests). */
    public static function reset(): void
    {
        self::$instance = new self();
    }

    public function defer(callable $callback): void
    {
        $this->deferred[] = $callback;
    }

    public function delay(float $seconds, callable $callback): string
    {
        return $this->addTimer($seconds, $callback, null);
    }

    public function repeat(float $interval, callable $callback): string
    {
        return $this->addTimer($interval, $callback, $interval);
    }

    /** @param resource $stream */
    public function onReadable($stream, callable $callback): string
    {
        $id = 'r' . ++$this->nextId;
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $callback;
        return $id;
    }

    /** @param resource $stream */
    public function onWritable($stream, callable $callback): string
    {
        $id = 'w' . ++$this->nextId;
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $callback;
        return $id;
    }

    public function cancel(string $id): void
    {
        if (isset($this->timers[$id])) {
            // Timeouts are usually cancelled long before they are due; without
            // compaction the queue would hold every cancelled timeout until its
            // due time (hundreds of thousands under load).
            if (++$this->cancelledTimers > 1024 && $this->cancelledTimers > count($this->timers)) {
                $this->compactTimerQueue($id);
                return;
            }
        }
        unset(
            $this->readStreams[$id], $this->readCallbacks[$id],
            $this->writeStreams[$id], $this->writeCallbacks[$id],
            $this->timers[$id],
        );
    }

    /** @return array{timers: int, queued_timers: int, read_watchers: int, write_watchers: int} */
    public function stats(): array
    {
        return [
            'timers' => count($this->timers),
            'queued_timers' => $this->timerQueue->count(),
            'read_watchers' => count($this->readStreams),
            'write_watchers' => count($this->writeStreams),
        ];
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function stop(): void
    {
        $this->stopRequested = true;
    }

    /** Run until stop() is called or nothing is left to wait for. */
    public function run(): void
    {
        $this->runUntil(static fn (): bool => false);
    }

    /** Run until $done returns true, stop() is called, or the loop is idle. */
    public function runUntil(callable $done): void
    {
        if ($this->running) {
            throw new \LogicException('Event loop is already running');
        }
        $this->running = true;
        $this->stopRequested = false;
        try {
            while (!$this->stopRequested && !$done()) {
                if (!$this->tick()) {
                    break;
                }
            }
        } finally {
            $this->running = false;
        }
    }

    /** One iteration. Returns false when there is nothing left to do. */
    private function tick(): bool
    {
        if ($this->deferred) {
            $queue = $this->deferred;
            $this->deferred = [];
            foreach ($queue as $callback) {
                $callback();
            }
        }

        $hasStreams = $this->readStreams || $this->writeStreams;
        if (!$hasStreams && !$this->timers && !$this->deferred) {
            return false;
        }

        $timeout = $this->deferred ? 0.0 : $this->nextTimerTimeout();
        if ($this->stopRequested) {
            return true;
        }

        if ($hasStreams) {
            $read = $this->readStreams;
            $write = $this->writeStreams;
            $except = null;
            $sec = $timeout === null ? null : (int) $timeout;
            $usec = $timeout === null ? 0 : (int) (($timeout - (int) $timeout) * 1e6);
            // EINTR (e.g. a signal) makes stream_select fail; just loop again.
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready) {
                foreach ($read as $id => $_) {
                    if (isset($this->readCallbacks[$id])) {
                        ($this->readCallbacks[$id])($id);
                    }
                }
                foreach ($write as $id => $_) {
                    if (isset($this->writeCallbacks[$id])) {
                        ($this->writeCallbacks[$id])($id);
                    }
                }
            }
        } elseif ($timeout !== null && $timeout > 0) {
            usleep((int) ($timeout * 1e6));
        }

        $this->runDueTimers();
        return true;
    }

    private function addTimer(float $seconds, callable $callback, ?float $interval): string
    {
        $id = 't' . ++$this->nextId;
        $due = hrtime(true) / 1e9 + max(0.0, $seconds);
        $this->timers[$id] = [$due, $callback, $interval];
        // Earliest due first; the sequence keeps FIFO order for equal times.
        $this->timerQueue->insert($id, [-$due, $this->timerSeq--]);
        return $id;
    }

    private function compactTimerQueue(string $cancelId): void
    {
        unset($this->timers[$cancelId]);
        $this->timerQueue = new \SplPriorityQueue();
        $this->timerQueue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        foreach ($this->timers as $id => [$due]) {
            $this->timerQueue->insert($id, [-$due, $this->timerSeq--]);
        }
        $this->cancelledTimers = 0;
    }

    private function nextTimerTimeout(): ?float
    {
        while (!$this->timerQueue->isEmpty()) {
            $id = $this->timerQueue->top();
            if (!isset($this->timers[$id])) {
                $this->timerQueue->extract(); // cancelled
                $this->cancelledTimers = max(0, $this->cancelledTimers - 1);
                continue;
            }
            return max(0.0, $this->timers[$id][0] - hrtime(true) / 1e9);
        }
        return null;
    }

    private function runDueTimers(): void
    {
        $now = hrtime(true) / 1e9;
        while (!$this->timerQueue->isEmpty()) {
            $id = $this->timerQueue->top();
            if (!isset($this->timers[$id])) {
                $this->timerQueue->extract();
                $this->cancelledTimers = max(0, $this->cancelledTimers - 1);
                continue;
            }
            [$due, $callback, $interval] = $this->timers[$id];
            if ($due > $now) {
                break;
            }
            $this->timerQueue->extract();
            if ($interval === null) {
                unset($this->timers[$id]);
            } else {
                $this->timers[$id][0] = $now + $interval;
                $this->timerQueue->insert($id, [-($now + $interval), $this->timerSeq--]);
            }
            $callback($id);
        }
    }
}
