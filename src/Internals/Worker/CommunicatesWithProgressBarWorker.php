<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Worker;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\Internals\ProgressBarWorker;
use parallel\Channel;

trait CommunicatesWithProgressBarWorker {

    /**
     * @var TwoWayChannel|Closure|null Channel of communication between Task and ProgressBar
     */
    private TwoWayChannel | Closure | null $progressbar_channel = null;

    /**
     * @var TwoWayChannel|Closure|null Channel of communication between Task and Console output
     */
    private TwoWayChannel | Closure | null $console_channel = null;

    final public function connectProgressBar(string | Closure $uuid, string $identifier = null): bool {
        if (! PARALLEL_EXT_LOADED) {
            $this->progressbar_channel = $uuid;

            return true;
        }

        // store worker identifier
        $this->identifier = $identifier;

        // open channel if not already opened
        while ($this->progressbar_channel === null) {
            // open channel to communicate with the Runner instance
            try { $this->progressbar_channel = TwoWayChannel::open(ProgressBarWorker::class.'@'.$uuid);
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        return true;
    }

    final public function connectConsole(string | Closure $uuid, string $identifier = null): bool {
        // on non-threaded environments the Runner provides a closure that writes to ConsoleOutput
        if (! PARALLEL_EXT_LOADED) {
            $this->console_channel = $uuid;
        }

        // on threaded environments there is no console coordinator to connect to:
        // messages are written directly to STDERR when no progress bar is active
        return true;
    }

    final public function setMessage(string $message, string $name = 'message'): void {
        $this->newProgressBarAction(__FUNCTION__, $message, $name);
    }

    final public function advance(int $steps = 1): void {
        $this->newProgressBarAction(__FUNCTION__, $steps);
    }

    final public function setProgress(int $step): void {
        $this->newProgressBarAction(__FUNCTION__, $step);
    }

    final public function display(): void {
        $this->newProgressBarAction(__FUNCTION__);
    }

    final public function clear(): void {
        $this->newProgressBarAction(__FUNCTION__);
    }

    final public function write(string $message, bool $newline = false): void {
        $this->sendOutputMessage(new Commands\Output\WriteOutputMessage($message, $newline));
    }

    final public function writeln(string $message): void {
        $this->write($message, true);
    }

    private function sendOutputMessage(Commands\Output\WriteOutputMessage $message): void {
        // if a progress bar is active, route the message through the ProgressBar worker
        if ($this->progressbar_channel !== null) {
            if (PARALLEL_EXT_LOADED) {
                $this->progressbar_channel->send($message);
            } else {
                ($this->progressbar_channel)($message);
            }

            return;
        }

        // if a console output channel is connected, route the message there
        if ($this->console_channel !== null) {
            if (PARALLEL_EXT_LOADED) {
                $this->console_channel->send($message);
            } else {
                ($this->console_channel)($message);
            }

            return;
        }

        // fallback when no coordinator is available
        fwrite(STDERR, $message->args[0].($message->args[1] ? PHP_EOL : ''));
    }

    private function newProgressBarAction(string $action, ...$args): void {
        // check if progressbar is active
        if ($this->progressbar_channel === null) return;

        $message = new Commands\ProgressBar\ProgressBarActionMessage(
            action: $action,
            args:   $args,
        );

        // check if parallel is available
        if (PARALLEL_EXT_LOADED) {
            // report memory usage
            $this->progressbar_channel->send(new Commands\ProgressBar\StatsReportMessage(
                worker_id:    $this->identifier,
                memory_usage: memory_get_usage(),
            ));
            // request ProgressBar action
            $this->progressbar_channel->send($message);

            return;
        }

        // redirect action to ProgressBar executor
        ($this->progressbar_channel)($message);
    }

}
