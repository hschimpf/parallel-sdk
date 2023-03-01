<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

final class ProcessedTask {

    /**
     * @param  ParallelWorker  $worker  Worker that processed the task
     * @param  mixed  $result  Result of the task
     */
    public function __construct(
        private ParallelWorker $worker,
        private mixed $result,
    ) {}

    /**
     * @return ParallelWorker
     */
    public function getWorker(): ParallelWorker {
        return $this->worker;
    }

    /**
     * @return mixed
     */
    public function getResult(): mixed {
        return $this->result;
    }

}
