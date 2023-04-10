<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::await()} action
 */
final class WaitTasksCompletionMessage extends ParallelCommandMessage {

    /**
     * @param  Closure  $should_keep_waiting
     */
    public function __construct(Closure $should_keep_waiting) {
        parent::__construct('await', [ $should_keep_waiting ]);
    }

}
