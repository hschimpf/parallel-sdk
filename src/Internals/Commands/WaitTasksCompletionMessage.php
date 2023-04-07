<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands;

use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::await()} action
 */
final class WaitTasksCompletionMessage extends ParallelCommandMessage {

    public function __construct() {
        parent::__construct('await');
    }

}
