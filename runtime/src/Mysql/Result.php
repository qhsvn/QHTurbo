<?php

declare(strict_types=1);

namespace QHTurbo\Runtime\Mysql;

/**
 * Query result. SELECT-like queries fill $rows (associative arrays with
 * native int/float values); others report affected rows and insert id.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class Result implements \IteratorAggregate, \Countable
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $affectedRows = 0,
        public readonly int|string $insertId = 0,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /** First column of the first row. */
    public function value(): mixed
    {
        $row = $this->rows[0] ?? null;
        return $row === null ? null : reset($row);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->rows);
    }
}
