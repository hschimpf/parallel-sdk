<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\ProgressBar;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;

/**
 * Message sent to {@see \HDSSolutions\Console\Parallel\Internals\Runner}
 * to execute {@see \HDSSolutions\Console\Parallel\Internals\Runner\HasSharedProgressBar::statsReport()}.
 */
final readonly class StatsReportMessage extends ParallelCommandMessage {

    /**
     * @param  string  $worker_id
     * @param  int  $memory_usage
     */
    public function __construct(string $worker_id, int $memory_usage) {
        parent::__construct('stats_report', [ $worker_id, $memory_usage ]);
    }

}
