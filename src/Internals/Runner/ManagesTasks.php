<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Contracts\ParallelWorker;
use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\Internals\Worker;
use HDSSolutions\Console\Parallel\RegisteredWorker;
use HDSSolutions\Console\Parallel\Task;
use parallel\Channel;
use parallel\Future;
use RuntimeException;
use Throwable;
use parallel;

trait ManagesTasks {

    /** @var int Current Task ID */
    private int $task_id = 0;

    /** @var Task[] Collection of tasks */
    private array $tasks = [];

    /** @var ?Channel Channel to wait for tasks started event */
    private ?Channel $starter = null;

    /** @var int[] Identifiers of pending tasks */
    private array $pending_tasks = [];

    /** @var Future[] Collection of running tasks */
    private array $running_tasks = [];

    private function hasCpuAvailable(): bool {
        // return if there is available CPU
        return count($this->running_tasks) < $this->getMaxCpuUsage();
    }

    private function hasPendingTasks(): bool {
        return !empty($this->pending_tasks);
    }

    private function hasRunningTasks(): bool {
        return !empty($this->running_tasks);
    }

    private function cleanFinishedTasks(): void {
        $finished_tasks = [];
        foreach ($this->running_tasks as $idx => $running_task) {
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

                // add future idx to finished tasks list
                $finished_tasks[] = $idx;
            }
        }

        // remove finished tasks from futures
        foreach ($finished_tasks as $idx) unset($this->running_tasks[$idx]);
    }

    private function startNextPendingTask(): void {
        // get next available pending task
        $task = $this->tasks[$task_id = array_shift($this->pending_tasks)];
        $task->setState(Task::STATE_Starting);

        // process task inside a thread (if parallel extension is available)
        if (PARALLEL_EXT_LOADED) {
            // create starter channel to wait threads start event
            $this->starter ??= Channel::make(sprintf('starter@%s', $this->uuid));

            // parallel available, process task inside a thread
            $this->running_tasks[$task_id] = parallel\run(static function(string $uuid, int $task_id, RegisteredWorker $registered_worker, Task $task): array {
                // get Worker class to instantiate
                $worker_class = $registered_worker->getWorkerClass();

                /** @var ParallelWorker $worker Instance of the Worker */
                $worker = new $worker_class(...$registered_worker->getArgs());
                // build task params
                $params = $worker instanceof Worker
                    // process task using local Worker
                    ? [ $registered_worker->getClosure(), ...$task->getInput() ]
                    // process task using user Worker
                    : [ ...$task->getInput() ];

                // check if worker has ProgressBar enabled
                if ($registered_worker->hasProgressEnabled()) {
                    // connect worker to ProgressBar
                    $worker->connectProgressBar($uuid, $GLOBALS['worker_thread_id'] ??= sprintf('%s@%s', $uuid, substr(md5(uniqid($worker_class, true)), 0, 16)));
                }

                // notify that thread started
                Channel::open(sprintf('starter@%s', $uuid))->send(true);

                // process task
                $worker->start(...$params);

                // return task identifier and result
                return [ $task_id, $worker->getResult() ];
            }, [
                // send UUID for starter channel
                $this->uuid,
                // send task id
                $task_id,
                // send task assigned worker
                $this->workers[$task->getWorkerId()],
                // send task to process
                $task,
            ]);

            // wait for thread to start
            if (($this->starter?->recv() ?? true) !== true) {
                throw new RuntimeException('Failed to start Task');
            }

            // update Task state
            $task->setState(Task::STATE_Processing);

        } else {
            // get registered worker
            $registered_worker = $this->workers[$task->getWorkerId()];
            // get Worker class to instantiate
            $worker_class = $registered_worker->getWorkerClass();

            /** @var ParallelWorker $worker Instance of the Worker */
            $worker = new $worker_class(...$registered_worker->getArgs());
            // build task params
            $params = $worker instanceof Worker
                // process task using local Worker
                ? [ $registered_worker->getClosure(), ...$task->getInput() ]
                // process task using user Worker
                : [ ...$task->getInput() ];

            // check if worker has ProgressBar enabled
            if ($registered_worker->hasProgressEnabled()) {
                // init progressbar
                $this->initProgressBar();
                // register worker
                $this->progressBar->processMessage(new Commands\ProgressBar\ProgressBarRegistrationMessage(
                    worker: $worker_class,
                    steps:  $registered_worker->getSteps(),
                ));
                // connect worker to ProgressBar
                $worker->connectProgressBar(fn(Commands\ProgressBar\ProgressBarActionMessage $message) => $this->progressBar->processMessage($message));
            }

            $task->setState(Task::STATE_Processing);

            // process task using worker
            $worker->start(...$params);

            // store Worker result
            $this->running_tasks[$task_id] = [ $task_id, $worker->getResult() ];
        }
    }

}
