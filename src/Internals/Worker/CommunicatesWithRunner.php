<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Worker;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\Internals\Runner;
use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use parallel\Channel;

trait CommunicatesWithRunner {

    /**
     * @var TwoWayChannel|Closure|null Channel of communication between the worker and the Runner
     */
    private TwoWayChannel | Closure | null $runner_channel = null;

    /**
     * @var bool Whether the worker has an active ProgressBar
     */
    private bool $progress_enabled = false;

    /**
     * @var string|null UUID used to open the Runner channel
     */
    private ?string $runner_uuid = null;

    final public function connectRunner(string | Closure $uuid, string $identifier = null, bool $progress_enabled = false): bool {
        $this->progress_enabled = $progress_enabled;
        if (is_string($uuid)) {
            $this->runner_uuid = $uuid;
        }

        if (! PARALLEL_EXT_LOADED) {
            $this->runner_channel = $uuid;

            return true;
        }

        // store worker identifier
        $this->identifier = $identifier;

        // open channel if not already opened
        while ($this->runner_channel === null) {
            // open channel to communicate with the Runner instance
            try { $this->runner_channel = TwoWayChannel::open(Runner::class.'@'.$uuid);
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        return true;
    }

    final protected function getRunnerChannel(): TwoWayChannel {
        if ($this->runner_channel instanceof TwoWayChannel) {
            return $this->runner_channel;
        }

        $uuid = property_exists($this, 'uuid') ? $this->uuid : ($this->runner_uuid ?? null);
        if ($uuid === null) {
            throw new \RuntimeException('Cannot determine Runner UUID');
        }

        while ($this->runner_channel === null) {
            // open channel to communicate with the Runner instance
            try { $this->runner_channel = TwoWayChannel::open(Runner::class.'@'.$uuid);
            // wait 1ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(1_000); }
        }

        return $this->runner_channel;
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
        if ($this->runner_channel !== null) {
            if (PARALLEL_EXT_LOADED) {
                $this->runner_channel->send($message);
            } else {
                ($this->runner_channel)($message);
            }

            return;
        }

        // fallback when no coordinator is available: write to a fresh stderr stream
        $stream = fopen('php://stderr', 'w');
        if ($stream !== false) {
            fwrite($stream, $message->args[0].($message->args[1] ? PHP_EOL : ''));
            fclose($stream);
        }
    }

    private function newProgressBarAction(string $action, ...$args): void {
        // check if progressbar is active
        if (!$this->progress_enabled || $this->runner_channel === null) return;

        $message = new Commands\ProgressBar\ProgressBarActionMessage(
            action: $action,
            args:   $args,
        );

        // check if parallel is available
        if (PARALLEL_EXT_LOADED) {
            // report memory usage
            $this->runner_channel->send(new Commands\ProgressBar\StatsReportMessage(
                worker_id:    $this->identifier,
                memory_usage: memory_get_usage(),
            ));
            $this->runner_channel->send($message);

            return;
        }

        ($this->runner_channel)($message);
    }

}
