<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\ProgressBarWorker;

use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;

trait HasChannels {

    /**
     * @var TwoWayChannel Communication channel for the progress bar
     */
    private TwoWayChannel $progressbar_channel;

    private function openChannels(): void {
        if ( !PARALLEL_EXT_LOADED) return;

        // channel to receive and process ProgressBar events
        $this->progressbar_channel = TwoWayChannel::make(self::class);
    }

    protected function recv(): mixed {
        return $this->progressbar_channel->receive();
    }

    protected function send(mixed $value): mixed {
        return $this->progressbar_channel->send($value);
    }

    protected function release(): void {
        $this->progressbar_channel->release();
    }

    private function closeChannels(): void {
        // gracefully join
        $this->progressbar_channel->send(false);
        // close channel
        $this->progressbar_channel->close();
    }

}
