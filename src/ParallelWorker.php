<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use RuntimeException;
use Throwable;

abstract class ParallelWorker implements Contracts\ParallelWorker {
    use Internals\Worker\CommunicatesWithProgressBarWorker;

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
    private mixed $result = null;

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

    final public function getResult(): mixed {
        if ($this->state !== self::STATE_Finished) {
            throw new RuntimeException('This Worker hasn\'t been yet processed the task');
        }

        return $this->result;
    }

}
