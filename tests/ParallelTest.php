<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests;

use HDSSolutions\Console\Parallel\Scheduler;
use PHPUnit\Framework\TestCase;
use Throwable;
use function parallel\bootstrap;

final class ParallelTest extends TestCase {

    public function testThatParallelExtensionIsAvailable(): void {
        // check that ext-parallel is available
        $this->assertTrue($loaded = extension_loaded('parallel'), 'Parallel extension isn\'t available');

        // check if extension is available
        if ($loaded) {
            // set parallel bootstrap file
            bootstrap(__DIR__.'/config/bootstrap.php');
        }
    }

    /**
     * @depends testThatParallelExtensionIsAvailable
     */
    public function testParallel(): void {
        $workers = [
            new TestWorker(),
            new AnotherWorker(),
        ];
        $tasks = [];

        foreach ($workers as $idx => $worker) {
            // register worker
            Scheduler::with($worker)
                // register task finished callback
                ->onTaskFinished(static function($task_no) {
                    echo sprintf("%s finished on %s\n",
                        $task_no, 'asdasd');
                });
            // build example "tasks"
            $tasks[get_class($worker)] = range(($idx + 1) * 100, ($idx + 1) * 100 + 25);
            // run example tasks
            foreach ($tasks[get_class($worker)] as $task) {
                try { Scheduler::runTask($task);
                } catch (Throwable) {
                    Scheduler::stop();
                }
            }
        }

        $results = [];
        // fetch processed tasks and store their results
        foreach (Scheduler::getProcessedTasks() as $task_result) {
            echo sprintf("Task result from #%s => %u\n",
                $worker_class = get_class($task_result->getWorker()),
                $result = $task_result->getResult());
            $results[$worker_class][] = $result;
        }

        Scheduler::disconnect();

        // check results
        foreach ($workers as $worker) {
            // get original tasks
            $worker_tasks = $tasks[get_class($worker)];
            // get tasks results
            $worker_results = $results[get_class($worker)];

            // tasks results must be the same count
            $this->assertCount(count($worker_tasks), $worker_results);
            // tasks results must be in different order
            $this->assertNotEquals($worker_tasks, $worker_results, 'Arrays are in the same order');
        }
    }

}
