<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\ConsoleWorker;

use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use parallel\Channel;

trait HasChannels {

    /**
     * @var TwoWayChannel Communication channel for the console output
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

    protected function release(): bool {
        return $this->send(true);
    }

    private function closeChannels(): void {
        // gracefully join
        $this->console_channel->send(false);
        // close channel
        $this->console_channel->close();
    }

}
