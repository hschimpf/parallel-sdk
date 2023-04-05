<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use HDSSolutions\Console\Parallel\Internals\Runner;
use parallel\Channel;
use parallel\Events\Event;
use parallel\Future;
use parallel\Runtime;

trait HasEater {

    private Future $eater;

    private function startEater(): void {
        if ( !PARALLEL_EXT_LOADED) return;

        // run an eater to keep updating states
        $this->eater = (new Runtime(PARALLEL_AUTOLOADER))->run(static function(): void {
            // create communication channel
            $channel = TwoWayChannel::make(Runner::class.':eater');
            // open communication channel with the Runner
            $runner_listener = TwoWayChannel::open(Runner::class);

            // notify successful start
            $channel->release();

            // every 25ms
            do { usleep(25_000);
                // send an Update message to the Runner instance
                $runner_listener->send(new Commands\UpdateMessage());
            // until we receive a stop signal on our channel
            } while (Event\Type::Close !== $channel->receive());

            // close communication channel
            $channel->close();
        });

        // open communication channel with the Eater
        do { try { $eater = TwoWayChannel::open(Runner::class.':eater');
        // wait 25ms if channel does not exist yet and retry
        } catch (Channel\Error\Existence) { usleep(25_000); }
        // try until channel is opened
        } while (($eater ?? null) === null);

        // wait until Eater starts
        $eater->receive();
    }

    private function stopEater(): void {
        // eater will send a final request
        $this->recv();
        // close eater thread
        $this->send(Event\Type::Close, eater: true);
    }

}
