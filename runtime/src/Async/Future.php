<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Async;

/**
 * Result of an asynchronous operation. Resolved exactly once with a value or
 * an error; callbacks registered with onDone() run when that happens.
 */
final class Future
{
    private bool $done = false;
    private mixed $value = null;
    private ?\Throwable $error = null;
    /** @var list<callable(?\Throwable, mixed): void> */
    private array $callbacks = [];

    public static function resolved(mixed $value = null): self
    {
        $future = new self();
        $future->resolve($value);
        return $future;
    }

    public function resolve(mixed $value = null): void
    {
        $this->complete(null, $value);
    }

    public function fail(\Throwable $error): void
    {
        $this->complete($error, null);
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /** @param callable(?\Throwable, mixed): void $callback */
    public function onDone(callable $callback): void
    {
        if ($this->done) {
            $callback($this->error, $this->value);
            return;
        }
        $this->callbacks[] = $callback;
    }

    /** Value of a completed future; rethrows its error. */
    public function result(): mixed
    {
        if (!$this->done) {
            throw new \LogicException('Future is not complete');
        }
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->value;
    }

    public function await(): mixed
    {
        return await($this);
    }

    private function complete(?\Throwable $error, mixed $value): void
    {
        if ($this->done) {
            throw new \LogicException('Future already completed');
        }
        $this->done = true;
        $this->error = $error;
        $this->value = $value;
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback($error, $value);
        }
    }
}
