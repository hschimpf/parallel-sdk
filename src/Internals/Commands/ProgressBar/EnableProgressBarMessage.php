<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\ProgressBar;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use HDSSolutions\Console\Parallel\Internals\Runner;

/**
 * Message sent to {@see Runner} to execute {@see Runner::enableProgressBar()} action
 */
final readonly class EnableProgressBarMessage extends ParallelCommandMessage {

    /**
     * @param  string  $identifier
     * @param  int  $steps
     */
    public function __construct(string $identifier, int $steps) {
        parent::__construct('enable_progress_bar', [ $identifier, $steps ]);
    }

}
