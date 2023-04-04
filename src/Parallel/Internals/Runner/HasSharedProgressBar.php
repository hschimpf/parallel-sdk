<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use Closure;
use HDSSolutions\Console\Parallel\Internals;
use parallel\Channel;
use parallel\Future;
use RuntimeException;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use parallel;

trait HasSharedProgressBar {

    /**
     * @var Future|Internals\ProgressBarWorker Instance of the ProgressBar worker
     */
    private Future|Internals\ProgressBarWorker $progressBar;

    /**
     * @var array Total of items processed per second (non-threaded)
     */
    private array $items = [];

    /**
     * @var ProgressBar|null ProgressBar instance for non-threaded Tasks execution
     */
    private ?ProgressBar $progressBar_ = null;

    /**
     * @var bool Flag to identify if ProgressBar is already started (non-threaded)
     */
    private bool $progressBarStarted = false;

    /**
     * @var Future|null Thread controlling the ProgressBar
     */
    private ?Future $progressBarThread = null;

    /**
     * @var Channel|null Channel of communication between ProgressBar and Tasks
     */
    private ?Channel $progressBarChannel = null;

    private function initProgressBar(): bool {
        // init ProgressBar only if not already working
        $this->progressBar ??= PARALLEL_EXT_LOADED
            // create a ProgressBarWorker instance inside a thread
            ? parallel\run(static function($uuid): void {
                // create ProgressBarWorker instance
                $progressBar = new Internals\ProgressBarWorker($uuid);
                // listen for events
                $progressBar->listen();
            }, [ $this->uuid ])

            // create a ProgressBar instance for non-threaded environment
            : new Internals\ProgressBarWorker($this->uuid);

        // wait until ProgressBar worker starts listening for events
        $this->progressBarChannel->recv();

return true;
///
        if ($this->progressBar_ !== null || $this->progressBarThread !== null) return true;

        // start a normal ProgressBar if parallel isn't available (non-threaded)
        if ( !PARALLEL_EXT_LOADED) {
            // create a non-threaded ProgressBar instance
            $this->progressBar_ = $this->createProgressBarInstance();
            return true;
        }

        // create a channel of communication between ProgressBar and Tasks
        $this->progressBarChannel = Channel::make(sprintf('progress-bar@%s', $this->uuid));

        // main thread memory reporter
        // FIXME this closure is copied and runs inside a thread, so memory report isn't accurate
        $main_memory_usage = static fn() => memory_get_usage();

        // decouple progress bar to a separated thread
        $this->progressBarThread = parallel\run(static function(string $uuid, Closure $createProgressBarInstance, Closure $main_memory_usage): void {
            // create ProgressBar worker instance
            $progressBarWorker = new ProgressBarWorker($uuid);
            // start ProgressBar
            $progressBarWorker->start($createProgressBarInstance, $main_memory_usage);

        }, [
            // send UUID for starter channel
            $this->uuid,
            // send ProgressBar creator
            fn() => $this->createProgressBarInstance(),
            // send main memory usage reporter
            $main_memory_usage,
        ]);

        // wait for ProgressBar thread to start
        if ($this->progressBarChannel->recv() !== true) {
            throw new RuntimeException('Failed to start ProgressBar');
        }

        return true;
    }

    private function createProgressBarInstance(): ProgressBar {
        $progressBar = new ProgressBar(new ConsoleOutput());

        // configure ProgressBar settings
        $progressBar->setBarWidth( 80 );
        $progressBar->setRedrawFrequency( 100 );
        $progressBar->minSecondsBetweenRedraws( 0.1 );
        $progressBar->maxSecondsBetweenRedraws( 0.2 );
        $progressBar->setFormat(" %current% of %max%: %message%\n".
                             " [%bar%] %percent:3s%%\n".
                             " elapsed: %elapsed:6s%, remaining: %remaining:-6s%, %items_per_second% items/s".(PARALLEL_EXT_LOADED ? "\n" : ',').
                             " memory: %threads_memory%\n");
        // set initial values
        $progressBar->setMessage('Starting...');
        $progressBar->setMessage('??', 'items_per_second');
        $progressBar->setMessage('??', 'threads_memory');

        return $progressBar;
    }

    private function getMemoryUsage(): string {
        // update memory usage for this thread
        $this->memory_stats['current'] = memory_get_usage(true);
        // update peak memory usage
        if ($this->memory_stats['current'] > $this->memory_stats['peak']) {
            $this->memory_stats['peak'] = $this->memory_stats['current'];
        }

        // current memory used
        $main = Helper::formatMemory($this->memory_stats['current']);
        // peak memory usage
        $peak = Helper::formatMemory($this->memory_stats['peak']);

        return "$main, ↑ $peak";
    }

    private function getItemsPerSecond(): string {
        // check for empty list
        if (empty($this->items)) return '0';

        // keep only last 15s for average
        $this->items = array_slice($this->items, -15, preserve_keys: true);

        // return the average of items processed per second
        return '~'.number_format(floor(array_sum($this->items) / count($this->items) * 100) / 100, 2);
    }

}
