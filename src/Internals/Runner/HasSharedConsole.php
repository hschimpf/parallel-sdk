<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use HDSSolutions\Console\Parallel\Internals\ConsoleWorker;
use parallel\Channel;
use parallel\Events\Event;
use parallel\Future;
use Symfony\Component\Console\Output\ConsoleOutput;
use parallel;

trait HasSharedConsole {

    /**
     * @var Future|ConsoleWorker|null Instance of the Console output worker
     */
    private Future | ConsoleWorker | null $console = null;

    /**
     * @var bool Flag to identify if Console output worker is already started
     */
    private bool $console_started = false;

    /**
     * @var TwoWayChannel|null Channel of communication with the Console output worker
     */
    private ?TwoWayChannel $console_channel = null;

    /**
     * @var ConsoleOutput Local ConsoleOutput used as a fallback on non-threaded environments
     */
    private ConsoleOutput $consoleOutput;

    private function initConsole(): void {
        // on non-threaded environments, just initialize the local ConsoleOutput
        if (! PARALLEL_EXT_LOADED) {
            $this->consoleOutput ??= new ConsoleOutput();

            return;
        }

        // already started
        if ($this->console_started) return;

        // create a ConsoleWorker instance inside a thread
        $this->console ??= parallel\run(static function(string $uuid): void {
            // create ConsoleWorker instance
            $console = new ConsoleWorker($uuid);
            // listen for events
            $console->listen();
        }, [ $this->uuid ]);

        // open communication channel with the Console worker
        while ($this->console_channel === null) {
            try { $this->console_channel = TwoWayChannel::open(ConsoleWorker::class.'@'.$this->uuid);
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        // wait until Console worker starts
        $this->console_channel->receive();
        $this->console_started = true;
    }

    private function stopConsole(): void {
        if (! PARALLEL_EXT_LOADED || ! $this->console_started) return;

        // stop Console worker instance
        $this->console_channel->send(Event\Type::Close);
        // wait until Console worker instance shutdowns
        $this->console_channel->receive();
    }

    private function writeOutput(string $message, bool $newline = true): void {
        $this->consoleOutput ??= new ConsoleOutput();
        $this->consoleOutput->getErrorOutput()->write($message, $newline);
    }

}
