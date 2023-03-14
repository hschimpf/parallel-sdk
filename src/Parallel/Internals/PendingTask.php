<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

final class PendingTask {

    /**
     * @param  RegisteredWorker  $registered_worker  Registered Worker that will process this task
     * @param  mixed  $data  Data of the Task
     */
    public function __construct(
        private RegisteredWorker $registered_worker,
        private mixed $data = null,
    ) {}

    /**
     * @return RegisteredWorker Registered Worker
     */
    public function getRegisteredWorker(): RegisteredWorker {
        return $this->registered_worker;
    }

    /**
     * @return mixed Data of the Task
     */
    public function getData(): mixed {
        return $this->data;
    }

}
