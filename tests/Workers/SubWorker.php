<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests\Workers;

use HDSSolutions\Console\Parallel\ParallelWorker;

final class SubWorker extends ParallelWorker {

    protected function process(int $seconds = 0): int {
        usleep(10_000);
        return $seconds;
    }

}
