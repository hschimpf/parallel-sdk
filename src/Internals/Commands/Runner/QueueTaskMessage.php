<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::queueTask()} action
 */
final readonly class QueueTaskMessage extends ParallelCommandMessage {

    /**
     * @param  array  $data
     */
    public function __construct(array $data) {
        parent::__construct('queue_task', [ $data ]);
    }

}
