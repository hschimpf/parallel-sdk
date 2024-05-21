<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use parallel\Channel;

trait HasChannels {

    /**
     * @var TwoWayChannel Communication channel to process events
     */
    private TwoWayChannel $channel;

    /**
     * @var ?TwoWayChannel Communication channel with the eater
     */
    private ?TwoWayChannel $eater_channel = null;

    /**
     * @var Channel Communication channel to output tasks
     */
    private Channel $tasks_channel;

    private function openChannels(): void {
        if ( !PARALLEL_EXT_LOADED) return;

        // channels to receive and process events
        $this->channel = TwoWayChannel::make(self::class.'@'.$this->uuid);
        // channel to output tasks
        $this->tasks_channel = Channel::make(self::class.'@'.$this->uuid.':tasks');
    }

    protected function getEaterChannel(): TwoWayChannel {
        // open channel if not already opened
        while ($this->eater_channel === null) {
            // open channel to communicate with the Eater instance
            try { $this->eater_channel = TwoWayChannel::open(self::class.'@'.$this->uuid.':eater');
            // wait 10ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(10_000); }
        }

        return $this->eater_channel;
    }

    protected function recv(): mixed {
        return $this->channel->receive();
    }

    protected function send(mixed $value, bool $eater = false): mixed {
        if (PARALLEL_EXT_LOADED) {
            if ($eater) $this->getEaterChannel()->send($value);
            else $this->channel->send($value);
        }

        return $value;
    }

    protected function release(bool $eater = false): void {
        $this->send(true, $eater);
    }

    private function closeChannels(): void {
        // gracefully join
        $this->channel->send(false);
        // close channel
        $this->channel->close();
    }

}
