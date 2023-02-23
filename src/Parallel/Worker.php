<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;

final class Worker extends ParallelWorker {

    public function __construct(
        private Closure $processor
    ) {}

    public function processTask(Closure $broadcast = null, ...$data): mixed {
        // execute original closure
        return ($this->processor)($broadcast, ...$data);
    }

}
