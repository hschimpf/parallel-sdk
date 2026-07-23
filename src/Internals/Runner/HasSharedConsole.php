<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Internals;
use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use Symfony\Component\Console\Output\ConsoleOutput;
use parallel\Channel;
use parallel\Events\Event;
use parallel\Future;
use parallel;

trait HasSharedConsole {

    /**
     * @var ConsoleOutput|null Local ConsoleOutput used on non-threaded environments
     */
    private ?ConsoleOutput $consoleOutput = null;

    /**
     * @var Future|Internals\ConsoleWorker|null Instance of the Console worker
     */
    private Future | Internals\ConsoleWorker | null $consoleWorker = null;

    /**
     * @var bool Flag to identify if Console worker is already started
     */
    private bool $console_worker_started = false;

    /**
     * @var TwoWayChannel|null Channel of communication with the Console worker
     */
    private ?TwoWayChannel $console_channel = null;

    private function initConsole(): void {
        // on non-threaded environments just prepare the local ConsoleOutput
        if (! PARALLEL_EXT_LOADED) {
            $this->consoleOutput ??= new ConsoleOutput();

            return;
        }

        // init Console worker, only if not already working
        $this->consoleWorker ??= parallel\run(static function(string $uuid): void {
            // create ConsoleWorker instance
            $console = new Internals\ConsoleWorker($uuid);
            // listen for events
            $console->listen();
        }, [ $this->uuid ]);

        // check if console worker is already started
        if ($this->console_worker_started) return;

        // open communication channel with the Console worker
        while ($this->console_channel === null) {
            // open channel to communicate with the Console worker instance
            try { $this->console_channel = TwoWayChannel::open(Internals\ConsoleWorker::class.'@'.$this->uuid);
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        // wait until Console worker starts
        $this->console_channel->receive();
        $this->console_worker_started = true;
    }

    private function writeOutput(string $message, bool $newline = true): void {
        if (PARALLEL_EXT_LOADED) {
            $this->initConsole();
            $this->console_channel?->send(new Internals\Commands\Output\WriteOutputMessage($message, $newline));

            return;
        }

        $this->consoleOutput ??= new ConsoleOutput();
        $this->consoleOutput->getErrorOutput()->write($message, $newline);
    }

    private function stopConsole(): void {
        if (! PARALLEL_EXT_LOADED || ! $this->console_worker_started) return;

        // stop Console worker instance
        $this->console_channel->send(Event\Type::Close);
        // wait until Console worker shutdowns
        $this->console_channel->receive();
    }

}
