<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::removeAllTasks()} action
 */
final readonly class RemoveAllTasksMessage extends ParallelCommandMessage {

    public function __construct() {
        parent::__construct('remove_all_tasks');
    }

}
