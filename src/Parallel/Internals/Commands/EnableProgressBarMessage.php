<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands;

final class EnableProgressBarMessage extends ParallelCommandMessage {

    /**
     * @param  int  $steps
     */
    public function __construct(int $steps) {
        parent::__construct('enable_progress_bar', [ $steps ]);
    }

}
