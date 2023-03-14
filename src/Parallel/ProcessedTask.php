<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

final class ProcessedTask {

    /**
     * @param  string  $worker_class  Worker class used to process the Task
     * @param  mixed  $result  Result of the Task
     */
    public function __construct(
        private string $worker_class,
        private mixed $result,
    ) {}

    /**
     * @return string Worker class that processed the Task
     */
    public function getWorkerClass(): string {
        return $this->worker_class;
    }

    /**
     * @return mixed Result of the processed Task
     */
    public function getResult(): mixed {
        return $this->result;
    }

}
