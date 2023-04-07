<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests\Workers;

use HDSSolutions\Console\Parallel\ParallelWorker;
use HDSSolutions\Console\Parallel\Scheduler;
use Throwable;

final class WorkerWithSubWorkers extends ParallelWorker {

    protected function process(int $subtasks = 0): array {
        Scheduler::using(SubWorker::class);

        foreach ($subtasks = range(1, $subtasks) as $subtask) {
            try { Scheduler::runTask($subtask);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        Scheduler::awaitTasksCompletion();

        $results = [];
        foreach (Scheduler::getTasks() as $task) {
            $results[] = $task->getResult();
        }

        Scheduler::removeTasks();

        return $results;
    }

}
