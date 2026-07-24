<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests\Workers;

use HDSSolutions\Console\Parallel\ParallelWorker;

final class Writer extends ParallelWorker {

    protected function process(int $n = 0): int {
        $this->setMessage(sprintf('Task #%d', $n));
        $this->writeln(sprintf('Starting #%d', $n));
        $this->writeln(sprintf('Done #%d', $n));
        $this->advance();

        return $n;
    }

}
