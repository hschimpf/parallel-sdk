<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::getRegisteredWorker()} action
 */
final class GetRegisteredWorkerMessage extends ParallelCommandMessage {

    /**
     * @param  string  $worker
     */
    public function __construct(string $worker) {
        parent::__construct('get_registered_worker', [ $worker ]);
    }

}
