<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::removeTask()} action
 */
final readonly class RemoveTaskMessage extends ParallelCommandMessage {

    /**
     * @param  int  $task_id
     */
    public function __construct(int $task_id) {
        parent::__construct('remove_task', [ $task_id ]);
    }

}
