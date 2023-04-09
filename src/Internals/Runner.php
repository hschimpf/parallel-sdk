<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Common;
use HDSSolutions\Console\Parallel\RegisteredWorker;
use HDSSolutions\Console\Parallel\Task;
use RuntimeException;
use Throwable;

final class Runner {
    use Runner\HasChannels;
    use Runner\HasEater;
    use Runner\HasSharedProgressBar;

    use Runner\ManagesWorkers;
    use Runner\ManagesTasks;

    use Common\ListenEventsAndExecuteActions;

    /** @var ?int Max CPU usage count */
    private ?int $max_cpu_count = null;

    public function __construct(
        private string $uuid,
    ) {
        $this->openChannels();
        $this->startEater();
    }

    protected function afterListening(): void {
        $this->stopEater();
        $this->stopRunningTasks();
        $this->closeChannels();
    }

    private function getMaxCpuUsage(): int {
        // return configured max CPU usage
        return $this->max_cpu_count ??= (isset($_SERVER['PARALLEL_MAX_COUNT']) ? (int) $_SERVER['PARALLEL_MAX_COUNT'] : cpu_count( (float) ($_SERVER['PARALLEL_MAX_PERCENT'] ?? 1.0) ));
    }

    protected function getRegisteredWorker(string $worker): RegisteredWorker | false {
        if ( !array_key_exists($worker, $this->workers_hashmap)) {
            return $this->send(false);
        }

        // set worker as the currently selected one
        return $this->selectWorker($this->workers_hashmap[$worker])
             ->send($this->getSelectedWorker());
    }

    protected function registerWorker(string | Closure $worker, array $args = []): RegisteredWorker {
        // check if worker is already registered
        if (is_string($worker) && array_key_exists($worker, $this->workers_hashmap)) {
            throw new RuntimeException(sprintf('Worker class "%s" is already registered', $worker));
        }

        // register worker
        $this->workers[] = $registered_worker = new RegisteredWorker(
            uuid:         $this->uuid,
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

    protected function queueTask(array $data): int {
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

    protected function getTasks(): array | false {
        if ( !PARALLEL_EXT_LOADED) {
            return $this->tasks;
        }

        foreach ($this->tasks as $task) {
            $this->tasks_channel->send($task);
        }
        $this->tasks_channel->send(false);

        return false;
    }

    protected function removeAllTasks(): bool {
        $this->stopRunningTasks();

        $this->tasks = [];
        $this->pending_tasks = [];

        return $this->send(true);
    }

    protected function removePendingTasks(): bool {
        // clear pending tasks
        $this->pending_tasks = [];

        return $this->send(true);
    }

    protected function stopRunningTasks(bool $should_return = false): bool {
        // kill all running threads
        foreach ($this->running_tasks as $task_id => $running_task) {
            // check if future is already done working
            if ( !PARALLEL_EXT_LOADED || $running_task->done()) {
                // store the ProcessedTask
                try {
                    // get the result of the process
                    [ $task_id, $result ] = PARALLEL_EXT_LOADED ? $running_task->value() : $running_task;
                    // ignore result if Task was removed, probably through Scheduler::removeTasks()
                    if (!array_key_exists($task_id, $this->tasks)) continue;
                    // store result and update state of the Task
                    $this->tasks[$task_id]
                        ->setResult($result)
                        ->setState(Task::STATE_Processed);
                } catch (Throwable) {}

            } else {
                // cancel running task
                try { $running_task->cancel();
                } catch (Throwable) {}
                // change task state to Cancelled
                $this->tasks[$task_id]->setState(Task::STATE_Cancelled);
            }
        }

        $this->running_tasks = [];

        if ($should_return) return $this->send(true);

        return true;
    }

    protected function enableProgressBar(string $worker_id, int $steps): bool {
        if ( !array_key_exists($worker_id, $this->workers_hashmap)) {
            throw new RuntimeException('Worker is not defined');
        }

        // get registered Worker
        $worker = $this->workers[$this->workers_hashmap[$worker_id]];
        // enable progress with specified steps
        $worker->withProgress(steps: $steps);

        $this->initProgressBar();

        $this->progressbar_channel->send(new Commands\ProgressBar\ProgressBarRegistrationMessage(
            worker: $worker->getWorkerClass(),
            steps:  $steps,
        ));

        return $this->send(true);
    }

    protected function update(): void {
        $this->cleanFinishedTasks();
        while ($this->hasCpuAvailable() && $this->hasPendingTasks()) {
            $this->startNextPendingTask();
        }

        $this->send($this->hasPendingTasks(), eater: true);

        if ($this->progressbar_started) {
            //
            $this->progressbar_channel->send(new Commands\ProgressBar\StatsReportMessage(
                worker_id:    '__main__',
                memory_usage: memory_get_usage(),
            ));
        }
    }

    protected function await(): bool {
        if (PARALLEL_EXT_LOADED) {
            return $this->send($this->hasPendingTasks() || $this->hasRunningTasks());
        }

        return true;
    }

    public function __destruct() {
        $this->stopProgressBar();
    }

}
