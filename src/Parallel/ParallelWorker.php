<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Messages\ProgressBarActionMessage;
use HDSSolutions\Console\Parallel\Internals\Messages\StatsReportMessage;
use parallel\Channel;
use RuntimeException;
use Throwable;

abstract class ParallelWorker implements Contracts\ParallelWorker {

    /**
     * @var int Current Worker state
     * @see Contracts\ParallelWorker::STATES
     */
    private int $state = self::STATE_New;

    /**
     * @var string Worker Identifier
     */
    private string $identifier;

    /**
     * @var Channel|Closure|null Channel of communication between Task and ProgressBar
     */
    private Channel | Closure | null $progressBarChannel = null;

    /**
     * @var float Time when process started
     */
    private float $started_at;

    /**
     * @var float Time when process finished
     */
    private float $finished_at;

    /**
     * @var mixed Worker execution result
     */
    private mixed $result;

    final public function getState(): int {
        return $this->state;
    }

    final public function connectProgressBar(string | Closure $uuid, string $identifier = null): bool {
        if ( !extension_loaded('parallel')) {
            $this->progressBarChannel = $uuid;

            return true;
        }

        // store worker identifier
        $this->identifier = $identifier;
        // connect to channel
        $this->progressBarChannel = Channel::open(sprintf('progress-bar@%s', $uuid));

        return true;
    }

    final public function start(...$args): void {
        if ($this->state !== self::STATE_New) {
            throw new RuntimeException('This Worker has been already started');
        }

        $this->state = self::STATE_Running;
        $this->started_at = microtime(true);

        try { $this->result = $this->process(...$args);
        } catch (Throwable) {}

        $this->finished_at = microtime(true);
        $this->state = self::STATE_Finished;
    }

    /**
     * Processes task data and returns the result
     *
     * @return mixed Task processing result
     */
    abstract protected function process(): mixed;

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

    final public function getStartedAt(): ?float {
        return $this->started_at ?? null;
    }

    final public function getFinishedAt(): ?float {
        return $this->finished_at ?? null;
    }

    final public function getProcessedTask(): ProcessedTask {
        if ($this->state !== self::STATE_Finished) {
            throw new RuntimeException('This Worker hasn\'t been started');
        }

        return new ProcessedTask(get_class($this), $this->result);
    }

    private function newProgressBarAction(string $action, ...$args): void {
        // check if parallel is available
        if (extension_loaded('parallel')) {
            // report memory usage
            $this->progressBarChannel->send(new StatsReportMessage(
                worker_id:    $this->identifier,
                memory_usage: memory_get_usage(),
            ));
            // request ProgressBar action
            $this->progressBarChannel->send(new ProgressBarActionMessage(
                action: $action,
                args:   $args,
            ));

            return;
        }

        // redirect action to ProgressBar executor
        ($this->progressBarChannel)($action, $args);
    }

}
