<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\ConsoleWorker;

use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use parallel;
use parallel\Channel;

trait HasChannels {

    /**
     * @var TwoWayChannel Communication channel for the console output worker
     */
    private TwoWayChannel $console_channel;

    private function openChannels(): void {
        if (! PARALLEL_EXT_LOADED) return;

        // channel to receive and process console output events
        $this->console_channel = TwoWayChannel::make(self::class.'@'.$this->uuid);
    }

    protected function recv(): mixed {
        return $this->console_channel->receive();
    }

    protected function send(mixed $value): mixed {
        return $this->console_channel->send($value);
    }

    protected function release(): void {
        if (! PARALLEL_EXT_LOADED) return;

        $this->console_channel->release();
    }

    private function closeChannels(): void {
        if (! PARALLEL_EXT_LOADED) return;

        // gracefully join
        $this->console_channel->send(false);
        // close channel
        $this->console_channel->close();
    }

}
