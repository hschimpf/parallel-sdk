<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests;

use HDSSolutions\Console\Parallel\Internals\Worker;
use HDSSolutions\Console\Parallel\RegisteredWorker;
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

    public function testThatWorkerMustBeDefinedValidates(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No worker is defined');
        Scheduler::runTask(123);
    }

    public function testThatWorkersCanBeRegistered(): void {
        $this->assertInstanceOf(RegisteredWorker::class,
            Scheduler::using(Workers\EmptyWorker::class, [ true, 123, 'false' ]));
    }

    public function testThatClosureCanBeUsedAsWorker(): void {
        Scheduler::using(static fn($input) => $input * 2);

        foreach ($tasks = range(1, 10) as $task) {
            try { Scheduler::runTask($task);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        Scheduler::awaitTasksCompletion();

        $results = [];
        foreach (Scheduler::getTasks() as $task) {
            $this->assertEquals(Worker::class, $task->getWorkerClass());
            $results[] = $task->getOutput();
        }
        // tasks results must be the same count
        $this->assertCount(count($tasks), $results);

        // remove all Tasks
        Scheduler::removeAllTasks();
    }

    public function testProgressBar(): void {
        $workers = [
            Workers\TestWorker::class,
            Workers\AnotherWorker::class,
        ];

        $multipliers = [ 2, 4, 8 ];

        // build example "tasks"
        $tasks = [];
        $total = 0;
        foreach ($workers as $idx => $worker) {
            $tasks[$worker] = range(($idx + 1) * 100, ($idx + 1) * 100 + 25);
            $total += count($tasks[$worker]);
        }

        foreach ($workers as $worker) {
            // register worker
            Scheduler::using($worker, $multipliers)
                // init progress bar for worker
                ->withProgress(steps: $total);

            // run example tasks
            foreach ($tasks[$worker] as $task) {
                try { Scheduler::runTask($task);
                } catch (Throwable) {
                    Scheduler::stop();
                }
            }
        }

        Scheduler::awaitTasksCompletion();

        $results = [];
        // fetch processed tasks and store their results
        foreach (Scheduler::getTasks() as $task) {
            $result = $task->getOutput();
            echo sprintf("Task result from #%s => %u\n",
                $worker_class = $task->getWorkerClass(),
                $result[1]);
            $results[$worker_class][] = $result;
        }

        // remove all Tasks
        Scheduler::removeAllTasks();

        // check results
        foreach ($workers as $worker) {
            // get original tasks
            $worker_tasks = $tasks[$worker];
            // get tasks results
            $worker_results = $results[$worker];

            // tasks results must be the same count
            $this->assertCount(count($worker_tasks), $worker_results);
            // tasks results must not be in different order
            $this->assertEquals($worker_tasks, array_column($worker_results, 0), 'Arrays are in different order');

            $result = array_shift($worker_results);
            $this->assertEquals($result[1], $result[0] * array_product($multipliers));
        }
    }

    /** @depends testThatParallelExtensionIsAvailable */
    public function testThatTasksCanBeRemovedFromQueue(): void {
        Scheduler::using(Workers\LongRunningWorker::class);

        foreach (range(1000, 20000, 50) as $ms) {
            try { Scheduler::runTask($ms);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        // wait 100ms and remove pending tasks
        usleep(100_000);
        Scheduler::removePendingTasks();

        // wait for running tasks to end
        Scheduler::awaitTasksCompletion();

        $has_pending_tasks = false;
        $has_processed_tasks = false;
        $has_cancelled_tasks = false;
        foreach (Scheduler::getTasks() as $task) {
            $has_pending_tasks = $has_pending_tasks || $task->isPending();
            $has_processed_tasks = $has_processed_tasks || $task->wasProcessed();
            $has_cancelled_tasks = $has_cancelled_tasks || $task->wasCancelled();
        }

        $this->assertTrue($has_pending_tasks);
        $this->assertTrue($has_processed_tasks);
        $this->assertFalse($has_cancelled_tasks);

        Scheduler::removeAllTasks();
    }

    /** @depends testThatParallelExtensionIsAvailable */
    public function testThatTasksCanBeCancelled(): void {
        Scheduler::using(Workers\LongRunningWorker::class);

        foreach (range(100, 20000, 50) as $ms) {
            try { Scheduler::runTask($ms);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        // wait 500ms and stop all
        usleep(500_000);
        Scheduler::stop();

        $has_pending_tasks = false;
        $has_processed_tasks = false;
        $has_cancelled_tasks = false;
        foreach (Scheduler::getTasks() as $task) {
            $has_pending_tasks = $has_pending_tasks || $task->isPending();
            $has_processed_tasks = $has_processed_tasks || $task->wasProcessed();
            $has_cancelled_tasks = $has_cancelled_tasks || $task->wasCancelled();
        }

        $this->assertTrue($has_pending_tasks, 'There are no pending Tasks left');
        $this->assertTrue($has_processed_tasks, 'There are no processed Tasks');
        $this->assertTrue($has_cancelled_tasks, 'There are no cancelled Tasks');

        Scheduler::removeAllTasks();
    }

    /** @depends testThatParallelExtensionIsAvailable */
    public function testThatChannelsDontOverlap(): void {
        Scheduler::using(Workers\WorkerWithSubWorkers::class);

        foreach (range(1, 10) as $task) {
            try { Scheduler::runTask($task);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        Scheduler::awaitTasksCompletion();

        foreach (Scheduler::getTasks() as $task) {
            // task result must be the same count as sub-tasks
            $this->assertCount($task->getInput()[0], $task->getOutput());
        }

        // remove all Tasks
        Scheduler::removeAllTasks();
    }

    /**
     * @depends testThatParallelExtensionIsAvailable
     */
    public function testThatCpuUsageCanBeControlled(): void {
        Scheduler::setMaxCpuCountUsage(1);
        Scheduler::using(static fn() => usleep(250_000));

        $start = time();
        foreach (range(1, 5) as $task) {
            try { Scheduler::runTask($task);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        Scheduler::awaitTasksCompletion();
        Scheduler::removeAllTasks();

        $this->assertGreaterThanOrEqual(1, time() - $start);
    }

}
