<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Closure;
use HDSSolutions\Console\Parallel\Exceptions\ParallelException;
use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use parallel\Channel;
use parallel\Events\Event;
use RuntimeException;
use Throwable;

final class Runner {
    use Runner\HasChannels;
    use Runner\HasEater;
    use Runner\HasSharedProgressBar;

    use Runner\ManagesWorkers;
    use Runner\ManagesTasks;

    /** @var ?int Max CPU usage count */
    private ?int $max_cpu_count = null;

    public function __construct(
        private string $uuid,
    ) {
        $this->openChannels();
        $this->startEater();
    }

    /**
     * Watch for events. This is used only on a multi-threaded environment
     */
    public function listen(): void {
        // notify successful start
        $this->release();

        // read messages
        try { while (Event\Type::Close !== $message = $this->recv()) {
            try {
                // check if we got a valid message
                if ( !($message instanceof ParallelCommandMessage)) {
                    throw new RuntimeException('Invalid message received!');
                }

                // process message
                $this->processMessage($message);

            } catch (Throwable $e) {
                // redirect exception to caller using output channel
                $this->send(new ParallelException($e));
            }

        }} catch (Channel\Error\Closed) {
            // TODO channel must not be closed
            $debug = true;
        }

        $this->stopEater();
        $this->stopRunningTasks();
        $this->closeChannels();
    }

    /**
     * @param  ParallelCommandMessage  $message
     *
     * @throws RuntimeException If the requested action isn't implemented
     */
    public function processMessage(Commands\ParallelCommandMessage $message): mixed {
        // check if action is implemented
        if ( !method_exists($this, $method = lcfirst(implode('', array_map('ucfirst', explode('_', $message->action)))))) {
            throw new RuntimeException(sprintf('Action "%s" not yet implemented', $message->action));
        }

        // execute action and return the result
        return $this->{$method}(...$message->args);
    }

    private function getMaxCpuUsage(): int {
        // return configured max CPU usage
        return $this->max_cpu_count ??= (isset($_SERVER['PARALLEL_MAX_COUNT']) ? (int) $_SERVER['PARALLEL_MAX_COUNT'] : cpu_count( (float) ($_SERVER['PARALLEL_MAX_PERCENT'] ?? 1.0) ));
    }

    private function getRegisteredWorker(string $worker): RegisteredWorker | false {
        if ( !array_key_exists($worker, $this->workers_hashmap)) {
            return $this->send(false);
        }

        // set worker as the currently selected one
        return $this->selectWorker($this->workers_hashmap[$worker])
             ->send($this->getSelectedWorker());
    }

    private function registerWorker(string | Closure $worker, array $args = []): RegisteredWorker {
        // check if worker is already registered
        if (is_string($worker) && array_key_exists($worker, $this->workers_hashmap)) {
            throw new RuntimeException(sprintf('Worker class "%s" is already registered', $worker));
        }

        // register worker
        $this->workers[] = $registered_worker = new RegisteredWorker(
            identifier:   $idx = count($this->workers),
            worker_class: is_string($worker) ? $worker : Worker::class,
            closure:      $worker instanceof Closure ? $worker : null,
            args:         $worker instanceof Closure ? [] : $args,
        );
        // and put index on the hashmap
        $this->workers_hashmap[$registered_worker->getIdentifier()] = $idx;

        return $this->selectWorker($idx)
                    ->send($registered_worker);
    }

    private function queueTask(array $data): int {
        if (null === $worker = $this->getSelectedWorker()) {
            // reject task scheduling, no worker is defined
            throw new RuntimeException('No worker is defined');
        }

        // register task
        $this->tasks[] = $task = new Task(
            identifier:   count($this->tasks),
            worker_class: $worker->getWorkerClass(),
            worker_id:    $this->selected_worker,
            data:         $data,
        );
        // and put identifier on the pending tasks list
        $this->pending_tasks[] = $task->getIdentifier();

        // if we are on a non-threaded environment,
        if ( !PARALLEL_EXT_LOADED) {
            // just process the Task
            $this->startNextPendingTask();
            // clean finished Task
            $this->cleanFinishedTasks();
        }

        return $this->send($task->getIdentifier());
    }

    private function getTasks(): array | false {
        if ( !PARALLEL_EXT_LOADED) {
            return $this->tasks;
        }

        foreach ($this->tasks as $task) {
            $this->tasks_link->send($task);
        }
        $this->tasks_link->send(false);

        return false;
    }

    private function removeTasks(): bool {
        $this->stopRunningTasks();

        $this->tasks = [];
        $this->pending_tasks = [];

        return $this->send(true);
    }

    private function update(): void {
        $this->cleanFinishedTasks();
        while ($this->hasCpuAvailable() && $this->hasPendingTasks()) {
            $this->startNextPendingTask();
        }

        $this->send($this->hasPendingTasks(), eater: true);
    }

    private function await(): bool {
        if (PARALLEL_EXT_LOADED) {
            return $this->send($this->hasPendingTasks() || $this->hasRunningTasks());
        }

        // wait for all tasks to finish processing
        while ($this->hasPendingTasks() || $this->hasRunningTasks()) usleep(25_000);

        return $this->send(true);
    }

    private function stopRunningTasks(): void {
        // TODO stop running tasks
    }

}
