<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands;

final class GetRegisteredWorkerMessage extends ParallelCommandMessage {

    /**
     * @param  string  $worker
     */
    public function __construct(string $worker) {
        parent::__construct('get_registered_worker', [ $worker ]);
    }

}
