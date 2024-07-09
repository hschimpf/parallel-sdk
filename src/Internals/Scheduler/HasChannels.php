<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Scheduler;

use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use HDSSolutions\Console\Parallel\Internals\Runner;
use parallel\Channel;

trait HasChannels {

    /**
     * @var TwoWayChannel|null Communication channel with Runner instance
     */
    private ?TwoWayChannel $runner_channel = null;

    /**
     * @var Channel|null Communication channel to receive Tasks
     */
    private ?Channel $tasks_channel = null;

    private function send(mixed $value): void {
        // open channel if not already opened
        while ($this->runner_channel === null) {
            // open channel to communicate with the Runner instance
            try { $this->runner_channel = TwoWayChannel::open(Runner::class.'@'.$this->uuid);
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        $this->runner_channel->send($value);
    }

    private function recv(bool $from_tasks_channel = false): mixed {
        // open channel if not already opened
        while ($this->runner_channel === null) {
            // open channel to communicate with the Runner instance
            try { $this->runner_channel = TwoWayChannel::open(Runner::class.'@'.$this->uuid);
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        // open channel if not already opened
        while ($this->tasks_channel === null) {
            // open channel to receive the tasks list
            try { $this->tasks_channel = Channel::open(Runner::class.'@'.$this->uuid.':tasks');
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        return $from_tasks_channel ? $this->tasks_channel->recv() : $this->runner_channel->receive();
    }

}
