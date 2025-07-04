<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Closure;
use HDSSolutions\Console\Parallel\Exceptions\NoWorkerDefinedException;
use HDSSolutions\Console\Parallel\Exceptions\WorkerAlreadyDefinedException;
use HDSSolutions\Console\Parallel\Exceptions\WorkerNotDefinedException;
use HDSSolutions\Console\Parallel\RegisteredWorker;
use HDSSolutions\Console\Parallel\Task;
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

    private function setMaxCpuCountUsage(int $count): int {
        return $this->send($this->max_cpu_count = $count);
    }

    private function setMaxCpuPercentageUsage(float $percentage): int {
        return $this->send($this->max_cpu_count = max(1, cpu_count($percentage)));
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
            throw new WorkerAlreadyDefinedException($worker);
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

    private function queueTask(array $data): int {
        if (null === $worker = $this->getSelectedWorker()) {
            // reject task scheduling, no worker is defined
            throw new NoWorkerDefinedException;
        }

        // get next task id
        $task_id = $this->task_id++;

        // register task
        $this->tasks[$task_id] = $task = new Task(
            identifier:   $task_id,
            worker_class: $worker->getWorkerClass(),
            worker_id:    $this->selected_worker,
            input:        $data,
        );
        // and put identifier on the pending tasks list
        $this->pending_tasks[$task_id] = $task->getIdentifier();

        // if we are on a non-threaded environment,
        if (! PARALLEL_EXT_LOADED) {
            // just process the Task
            $this->startNextPendingTask();
            // clean finished Task
            $this->cleanFinishedTasks();
        }

        return $this->send($task->getIdentifier());
    }

    private function getTasks(): array | false {
        if (! PARALLEL_EXT_LOADED) {
            return $this->tasks;
        }

        foreach ($this->tasks as $task) {
            $this->tasks_channel->send($task);
        }
        $this->tasks_channel->send(false);

        return false;
    }

    private function removeTask(int $task_id): bool {
        // remove it from pending tasks
        if (array_key_exists($task_id, $this->pending_tasks)) {
            unset($this->pending_tasks[$task_id]);
        }

        // remove it from running tasks
        if (array_key_exists($task_id, $this->running_tasks)) {
            // stop the task if it is still running
            try { $this->running_tasks[$task_id]->cancel();
            } catch (Throwable) {}

            unset($this->running_tasks[$task_id]);
        }

        // remove it from the task list
        if (array_key_exists($task_id, $this->tasks)) {
            unset($this->tasks[$task_id]);

            return $this->send(true);
        }

        return $this->send(false);
    }

    private function removeAllTasks(): bool {
        $this->stopRunningTasks();

        $this->tasks = [];
        $this->pending_tasks = [];

        return $this->send(true);
    }

    private function removePendingTasks(): bool {
        // clear pending tasks
        $this->pending_tasks = [];

        return $this->send(true);
    }

    private function stopRunningTasks(bool $should_return = false): bool {
        // kill all running threads
        foreach ($this->running_tasks as $task_id => $running_task) {
            // check if future is already done working
            if (! PARALLEL_EXT_LOADED || $running_task->done()) {
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

    private function enableProgressBar(string $worker_id, int $steps): bool {
        if ( !array_key_exists($worker_id, $this->workers_hashmap)) {
            throw new WorkerNotDefinedException;
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
        $this->progressbar_channel->receive();

        return $this->send(true);
    }

    private function update(): void {
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

    private function await(?int $wait_until = null): bool {
        if (PARALLEL_EXT_LOADED) {
            return $this->send(time() <= ($wait_until ?? time()) && ($this->hasPendingTasks() || $this->hasRunningTasks()));
        }

        return true;
    }

    public function __destruct() {
        $this->stopProgressBar();
    }

}
