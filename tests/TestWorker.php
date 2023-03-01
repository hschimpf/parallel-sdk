<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests;

use HDSSolutions\Console\Parallel\ParallelWorker;

final class TestWorker extends ParallelWorker {

    protected function processTask(int $number = 0): int {
        $microseconds = random_int(100, 500);
        echo sprintf("TestWorker >> Hello from task #%u, I'll wait %sms\n", $number, $microseconds);
        usleep($microseconds * 1000);

        return $number;
    }

}
