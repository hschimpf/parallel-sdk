<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\ProgressBar;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;

/**
 * Message sent to {@see ProgressBarWorker} to execute {@see ProgressBarWorker::progressBarAction()}
 */
final readonly class ProgressBarActionMessage extends ParallelCommandMessage {

    /**
     * @param  string  $action
     * @param  array  $args
     */
    public function __construct(string $action, array $args) {
        parent::__construct('progress_bar_action', [ $action, $args ]);
    }

}
