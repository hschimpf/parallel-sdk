<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

final class PendingTask {

    /**
     * @param  int  $worker_id  Worker identifier
     * @param  mixed  $data  Data of the Task
     */
    public function __construct(
        private int $worker_id,
        private mixed $data = null,
    ) {}

    /**
     * @return int Worker identifier
     */
    public function getWorkerId(): int {
        return $this->worker_id;
    }

    /**
     * @return mixed Data of the Task
     */
    public function getData(): mixed {
        return $this->data;
    }

}
