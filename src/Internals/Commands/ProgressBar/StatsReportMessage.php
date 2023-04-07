<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\ProgressBar;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;

/**
 * Message sent to {@see ProgressBarWorker} to execute {@see ProgressBarWorker::statsReport()}
 */
final class StatsReportMessage extends ParallelCommandMessage {

    /**
     * @param  string  $worker_id
     * @param  int  $memory_usage
     */
    public function __construct(string $worker_id, int $memory_usage) {
        parent::__construct('stats_report', [ $worker_id, $memory_usage ]);
    }

}
