<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::removePendingTasks()} action
 */
final readonly class RemovePendingTasksMessage extends ParallelCommandMessage {

    public function __construct() {
        parent::__construct('remove_pending_tasks');
    }

}
