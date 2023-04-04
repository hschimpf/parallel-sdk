<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\ProgressBarWorker;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

trait HasProgressBar {

    /**
     * @var ProgressBar ProgressBar instance
     */
    private ProgressBar $progressBar;

    private function createProgressBar(): ProgressBar {
        $this->progressBar = new ProgressBar(new ConsoleOutput());

        // configure ProgressBar settings
        $this->progressBar->setBarWidth( 80 );
        $this->progressBar->setRedrawFrequency( 100 );
        $this->progressBar->minSecondsBetweenRedraws( 0.1 );
        $this->progressBar->maxSecondsBetweenRedraws( 0.2 );
        $this->progressBar->setFormat(" %current% of %max%: %message%\n".
                             " [%bar%] %percent:3s%%\n".
                             " elapsed: %elapsed:6s%, remaining: %remaining:-6s%, %items_per_second% items/s".(PARALLEL_EXT_LOADED ? "\n" : ',').
                             " memory: %threads_memory%\n");
        // set initial values
        $this->progressBar->setMessage('Starting...');
        $this->progressBar->setMessage('??', 'items_per_second');
        $this->progressBar->setMessage('??', 'threads_memory');
    }

}
