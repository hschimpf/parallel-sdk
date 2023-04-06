<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Internals;
use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use parallel\Channel;
use parallel\Events\Event;
use parallel\Future;
use parallel;

trait HasSharedProgressBar {

    /**
     * @var Future|Internals\ProgressBarWorker Instance of the ProgressBar worker
     */
    private Future|Internals\ProgressBarWorker $progressBar;

    /**
     * @var bool Flag to identify if ProgressBar is already started
     */
    private bool $progressbar_started = false;

    /**
     * @var TwoWayChannel Channel of communication with the ProgressBar worker
     */
    private TwoWayChannel $progressbar_channel;

    private function initProgressBar(): void {
        // init ProgressBar worker, only if not already working
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

        // check if progressbar is already started, or we are on a non-threaded environment
        if ($this->progressbar_started || !PARALLEL_EXT_LOADED) return;

        // open communication channel with the ProgressBar worker
        do { try { $this->progressbar_channel = TwoWayChannel::open(Internals\ProgressBarWorker::class);
        // wait 25ms if channel does not exist yet and retry
        } catch (Channel\Error\Existence) { usleep(25_000); }
        // try until channel is opened
        } while (($this->progressbar_channel ?? null) === null);

        // wait until ProgressBar worker starts
        $this->progressbar_channel->receive();
        $this->progressbar_started = true;
    }

    private function stopProgressBar(): void {
        // stop ProgressBar worker instance
        $this->progressbar_channel->send(Event\Type::Close);
        // wait until ProgressBar instance shutdowns
        $this->progressbar_channel->receive();
    }

}
