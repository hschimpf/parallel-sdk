<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use RuntimeException;
use Throwable;

abstract class ParallelWorker implements Contracts\ParallelWorker {

    /**
     * @var int Current Worker state
     * @see Contracts\ParallelWorker::STATES
     */
    private int $state = self::STATE_New;

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

}
