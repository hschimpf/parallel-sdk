<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Closure;
use HDSSolutions\Console\Parallel\ParallelWorker;

final class Worker extends ParallelWorker {

    public function process(?Closure $processor = null, ...$data): mixed {
        // execute original closure
        return $processor(...$data);
    }

}
