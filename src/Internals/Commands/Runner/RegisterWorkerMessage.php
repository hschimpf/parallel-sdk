<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::registerWorker()} action
 */
final class RegisterWorkerMessage extends ParallelCommandMessage {

    /**
     * @param  string|Closure  $worker
     * @param  array  $args
     */
    public function __construct(string | Closure $worker, array $args) {
        parent::__construct('register_worker', [ $worker, $args ]);
    }

}
