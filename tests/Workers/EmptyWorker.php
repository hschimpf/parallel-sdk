<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests\Workers;

use HDSSolutions\Console\Parallel\ParallelWorker;

final class EmptyWorker extends ParallelWorker {

    protected function process(int $number = 0): mixed {
        return null;
    }

}
