<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests\Workers;

use HDSSolutions\Console\Parallel\ParallelWorker;

final class LongRunningWorker extends ParallelWorker {

    protected function process(int $goal = 0): int {
        $waited = 0;
        do {
            usleep(100_000);
            $waited += 100;
        } while ($waited <= $goal);

        return $goal;
    }

}
