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
     * @param  Closure  $or_until
     */
    public function __construct(Closure $or_until) {
        parent::__construct('await', [ $or_until ]);
    }

}
