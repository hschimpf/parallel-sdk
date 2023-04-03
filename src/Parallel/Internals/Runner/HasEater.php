<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\Internals\Runner;
use parallel\Channel;
use parallel\Events\Event;
use parallel\Future;
use parallel\Runtime;

trait HasEater {

    private Future $eater;

    private function startEater(): void {
        if ( !extension_loaded('parallel')) return;

        // run an eater to keep updating states
        $this->eater = (new Runtime(PARALLEL_AUTOLOADER))->run(static function(): void {
            $input = Channel::open(Runner::class.'@input');
            $output = Channel::open(Runner::class.'@eater');
            // every 25ms
            do { usleep(25_000);
                // send an Update message
                $input->send(new Commands\UpdateMessage());
            // until we receive a stop signal
            } while (Event\Type::Close !== $output->recv());
        });
    }

    private function stopEater(): void {
        // eater will send a final request
        $this->recv();
        // close eater thread
        $this->send(Event\Type::Close, eater: true);
    }

}
