<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\ProgressBar;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner\HasSharedProgressBar::registerProgressBar()} action
 */
final readonly class ProgressBarRegistrationMessage extends ParallelCommandMessage {

    /**
     * @param  string  $worker
     * @param  int  $steps
     */
    public function __construct(string $worker, int $steps = 0) {
        parent::__construct('register_progress_bar', [ $worker, $steps ]);
    }

}
