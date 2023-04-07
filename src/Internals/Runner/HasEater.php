<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use HDSSolutions\Console\Parallel\Internals\Runner;
use parallel\Events\Event;
use parallel\Future;
use parallel\Runtime;

trait HasEater {

    private Future $eater;

    private function startEater(): void {
        if ( !PARALLEL_EXT_LOADED) return;

        // run an eater to keep updating states
        $this->eater = (new Runtime(PARALLEL_AUTOLOADER))->run(static function(string $uuid): void {
            // create communication channel
            $channel = TwoWayChannel::make(Runner::class.'@'.$uuid.':eater');
            // open communication channel with the Runner
            $runner_listener = TwoWayChannel::open(Runner::class.'@'.$uuid);

            // notify successful start
            $channel->release();

            // every 25ms
            do { usleep(25_000);
                // send an Update message to the Runner instance
                $runner_listener->send(new Commands\Runner\UpdateMessage());
            // until we receive a stop signal on our channel
            } while (Event\Type::Close !== $channel->receive());

            // close communication channel
            $channel->close();
        }, [ $this->uuid ]);

        // wait until Eater starts
        $this->getEaterChannel()->receive();
    }

    private function stopEater(): void {
        // eater will send a final request
        $this->recv();
        // stop Eater instance
        $this->getEaterChannel()->send(Event\Type::Close);
    }

}
