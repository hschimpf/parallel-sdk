<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests;

use HDSSolutions\Console\Parallel\Internals\Worker;
use HDSSolutions\Console\Parallel\Scheduler;
use HDSSolutions\Console\Tests\Workers;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use function parallel\bootstrap;

final class ParallelTest extends TestCase {

    public function testThatParallelExtensionIsAvailable(): void {
        // check that ext-parallel is available
        $this->assertTrue($loaded = extension_loaded('parallel'), 'Parallel extension isn\'t available');

        // check if extension is available
        if ($loaded) {
            // set bootstrap file
            bootstrap(__DIR__.'/../vendor/autoload.php');
        }
    }

    /**
     * @depends testThatParallelExtensionIsAvailable
     */
    public function testThatWorkerMustBeDefinedValidates(): void {
        $this->expectException(RuntimeException::class);
        Scheduler::runTask(123);
    }

    /**
     * @depends testThatParallelExtensionIsAvailable
     */
    public function testThatClosureCanBeUsedAsWorker(): void {
        Scheduler::using(static function($input) {
            usleep(random_int(100, 500)  * 1000);
            return $input * 2;
        });

        foreach ($tasks = range(1, 10) as $task) {
            try { Scheduler::runTask($task);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        $results = [];
        foreach (Scheduler::getProcessedTasks() as $processed_task) {
            $this->assertEquals(Worker::class, $processed_task->getWorkerClass());
            $results[] = $processed_task->getResult();
        }
        // tasks results must be the same count
        $this->assertCount(count($tasks), $results);
    }

    /**
     * @depends testThatParallelExtensionIsAvailable
     */
    public function testParallel(): void {
        $workers = [
            Workers\TestWorker::class,
            Workers\AnotherWorker::class,
        ];
        $tasks = [];

        foreach ($workers as $idx => $worker) {
            // register worker
            Scheduler::using($worker);
            // build example "tasks"
            $tasks[$worker] = range(($idx + 1) * 100, ($idx + 1) * 100 + 25);
            // run example tasks
            foreach ($tasks[$worker] as $task) {
                try { Scheduler::runTask($task);
                } catch (Throwable) {
                    Scheduler::stop();
                }
            }
        }

        $results = [];
        // fetch processed tasks and store their results
        foreach (Scheduler::getProcessedTasks() as $processed_task) {
            echo sprintf("Task result from #%s => %u\n",
                $worker_class = $processed_task->getWorkerClass(),
                $result = $processed_task->getResult());
            $results[$worker_class][] = $result;
        }

        Scheduler::disconnect();

        // check results
        foreach ($workers as $worker) {
            // get original tasks
            $worker_tasks = $tasks[$worker];
            // get tasks results
            $worker_results = $results[$worker];

            // tasks results must be the same count
            $this->assertCount(count($worker_tasks), $worker_results);
            // tasks results must be in different order
            $this->assertNotEquals($worker_tasks, $worker_results, 'Arrays are in the same order');
        }
    }

}
