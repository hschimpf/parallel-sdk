<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Worker;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use HDSSolutions\Console\Parallel\Internals\Messages\ProgressBarActionMessage;
use HDSSolutions\Console\Parallel\Internals\Messages\StatsReportMessage;
use HDSSolutions\Console\Parallel\Internals\ProgressBarWorker;
use parallel\Channel;

trait CommunicatesWithProgressBarWorker {

    /**
     * @var TwoWayChannel|Closure|null Channel of communication between Task and ProgressBar
     */
    private TwoWayChannel | Closure | null $progressbar_channel = null;

    final public function connectProgressBar(string | Closure $uuid, string $identifier = null): bool {
        if ( !PARALLEL_EXT_LOADED) {
            $this->progressbar_channel = $uuid;

            return true;
        }

        // store worker identifier
        $this->identifier = $identifier;

        // open communication channel with the ProgressBar worker
        do { try { $this->progressbar_channel = TwoWayChannel::open(ProgressBarWorker::class.'@'.$uuid);
        // wait 25ms if channel does not exist yet and retry
        } catch (Channel\Error\Existence) { usleep(25_000); }
        // try until channel is opened
        } while (($this->progressbar_channel ?? null) === null);

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

    private function newProgressBarAction(string $action, ...$args): void {
        $message = new ProgressBarActionMessage(
            action: $action,
            args:   $args,
        );

        // check if parallel is available
        if (PARALLEL_EXT_LOADED) {
            // report memory usage
            $this->progressbar_channel->send(new StatsReportMessage(
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
