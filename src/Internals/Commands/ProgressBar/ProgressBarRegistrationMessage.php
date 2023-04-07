<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\ProgressBar;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\ProgressBarWorker;

/**
 * Message sent to {@see ProgressBarWorker} to execute {@see ProgressBarWorker::registerWorker()} action
 */
final class ProgressBarRegistrationMessage extends ParallelCommandMessage {

    /**
     * @param  string  $worker
     * @param  int  $steps
     */
    public function __construct(string $worker, int $steps = 0) {
        parent::__construct('register_worker', [ $worker, $steps ]);
    }

}
