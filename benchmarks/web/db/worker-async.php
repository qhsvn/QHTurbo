<?php
// Long-lived worker with the async MySQL client: the three queries run in
// parallel and other requests proceed while they wait (concurrency > 1).
use QHTurbo\Runtime\Mysql\Client;

use function QHTurbo\Runtime\Async\all;
use function QHTurbo\Runtime\Async\async;

$db = new Client((getenv('BENCH_MYSQL_URI') ?: 'mysql://qht:qhtpass@127.0.0.1:3306/qht') . '?pool=' . (getenv('BENCH_POOL') ?: 32));
return static function () use ($db): array {
    return all(array_map(
        static fn (int $i) => async(static fn () => $db->query('SELECT SLEEP(0.02) AS s, ? AS i', [$i])->first()),
        [1, 2, 3],
    ));
};
