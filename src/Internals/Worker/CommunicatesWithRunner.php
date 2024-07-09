<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Worker;

use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use HDSSolutions\Console\Parallel\Internals\Runner;
use parallel\Channel;

trait CommunicatesWithRunner {

    /**
     * @var TwoWayChannel|null Communication channel with the Runner
     */
    private ?TwoWayChannel $runner_channel = null;

    protected function getRunnerChannel(): TwoWayChannel {
        // open channel if not already opened
        while ($this->runner_channel === null) {
            // open channel to communicate with the Runner instance
            try { $this->runner_channel = TwoWayChannel::open(Runner::class.'@'.$this->uuid);
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        return $this->runner_channel;
    }

}
