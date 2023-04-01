<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands;

use Closure;

final class RegisterWorkerMessage extends ParallelCommandMessage {

    /**
     * @param  string|Closure  $worker
     * @param  array  $args
     */
    public function __construct(string | Closure $worker, array $args) {
        parent::__construct('register_worker', [ $worker, $args ]);
    }

}
