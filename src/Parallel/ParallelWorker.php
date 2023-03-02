<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use RuntimeException;

abstract class ParallelWorker implements Contracts\ParallelWorker {

    /**
     * @var int Current Worker state
     * @see Contracts\ParallelWorker::STATES
     */
    private int $state = self::STATE_New;

    /**
     * @var mixed Worker execution result
     */
    private mixed $result;

    final public function __construct() {}

    final public function getState(): int {
        return $this->state;
    }

    final public function start(...$args): void {
        if ($this->state !== self::STATE_New) {
            throw new RuntimeException('This Worker has been already started');
        }

        $this->state = self::STATE_Running;
        $this->result = $this->process(...$args);
        $this->state = self::STATE_Finished;
    }

    /**
     * Processes task data and returns the result
     *
     * @return mixed Task processing result
     */
    abstract protected function process(): mixed;

    final public function getProcessedTask(): ProcessedTask {
        if ($this->state !== self::STATE_Finished) {
            throw new RuntimeException('This Worker hasn\'t been started');
        }

        return new ProcessedTask(get_class($this), $this->result);
    }

}
