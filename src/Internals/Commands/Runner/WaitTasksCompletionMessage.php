<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use DateInterval;
use DateTime;
use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::await()} action
 */
final readonly class WaitTasksCompletionMessage extends ParallelCommandMessage {

    /**
     * @param  DateInterval|null  $wait_until
     */
    public function __construct(?DateInterval $wait_until = null) {
        parent::__construct('await', [ $wait_until === null ? null : (new DateTime())->add($wait_until)->getTimestamp() ]);
    }

}
